<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
$pageTitle = 'User Management';
$activePage = 'users';
require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
$users = $pdo->query('SELECT first_name, last_name, email, role, status, created_at FROM users ORDER BY created_at DESC')->fetchAll();
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>User Management</h2><p>Review BreadBreak accounts and access roles.</p></section>
<section class="panel"><div class="panel-heading"><h3>Registered Accounts</h3></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td><td><?php echo htmlspecialchars($user['email']); ?></td><td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td><td><span class="status"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span></td><td><?php echo htmlspecialchars(date('M j, Y', strtotime($user['created_at']))); ?></td></tr><?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="5">No user accounts found.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
