<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';

$pdo = getDatabaseConnection();
ensureRiderSupport($pdo);
$statusReasonColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'status_reason'")->fetchColumn();
if (!$statusReasonColumn) $pdo->exec("ALTER TABLE users ADD COLUMN status_reason VARCHAR(255) NULL AFTER status");
$pageTitle = 'User Management';
$activePage = 'users';
$errors = [];
$successMessage = $_SESSION['admin_user_success'] ?? '';
unset($_SESSION['admin_user_success']);
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$showOptions = ['10' => 10, '25' => 25, '50' => 50, '100' => 100, 'all' => 0];
$show = $_GET['show'] ?? '10';
$show = array_key_exists($show, $showOptions) ? $show : '10';
$page = max(1, (int) ($_GET['page'] ?? 1));
$modal = '';
$formData = [];
// GET requests have no row selected — the password/status/delete modals below
// render an initial hidden user_id from this default (JS overwrites it on open).
$userId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'create_user') {
        $formData = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'role' => strtolower(trim($_POST['role'] ?? '')),
        ];
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $modal = 'add-user-modal';

        if ($formData['first_name'] === '') $errors['first_name'] = 'First name is required.';
        if ($formData['last_name'] === '') $errors['last_name'] = 'Last name is required.';
        if ($formData['phone'] === '') $errors['phone'] = 'Phone number is required.';
        if ($formData['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!in_array($formData['role'], ['admin', 'staff', 'rider', 'customer'], true)) $errors['role'] = 'Please select a valid role.';
        if ($password === '') $errors['password'] = 'Password is required.';
        elseif (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if ($confirmPassword === '') $errors['confirm_password'] = 'Please confirm the password.';
        elseif ($password !== $confirmPassword) $errors['confirm_password'] = 'Passwords do not match.';

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $check->execute(['email' => $formData['email']]);
            if ($check->fetch()) {
                $errors['email'] = 'This email is already registered.';
            } else {
                $insert = $pdo->prepare('INSERT INTO users (first_name, last_name, phone, email, password, role, status) VALUES (:first_name, :last_name, :phone, :email, :password, :role, :status)');
                $insert->execute([
                    'first_name' => $formData['first_name'], 'last_name' => $formData['last_name'],
                    'phone' => $formData['phone'], 'email' => $formData['email'],
                    'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $formData['role'], 'status' => 'active',
                ]);
                $_SESSION['admin_user_success'] = 'User account created successfully.';
                header('Location: ' . BASE_URL . '/admin/users.php');
                exit;
            }
        }
    }

    if ($action === 'change_password') {
        $password = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $modal = 'password-modal';
        if ($password === '') $errors['new_password'] = 'Password is required.';
        elseif (strlen($password) < 8) $errors['new_password'] = 'Password must be at least 8 characters.';
        if ($confirmPassword === '') $errors['password_confirm'] = 'Please confirm the password.';
        elseif ($password !== $confirmPassword) $errors['password_confirm'] = 'Passwords do not match.';
        if (!$errors) {
            $update = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $update->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
            $_SESSION['admin_user_success'] = 'Password updated successfully.';
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
    }

    if ($action === 'update_status') {
        $newStatus = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $reason = trim($_POST['reason'] ?? '');
        $modal = 'status-modal';
        if ($newStatus === 'inactive' && $reason === '') $errors['reason'] = 'A reason is required when deactivating an account.';
        $target = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $target->execute(['id' => $userId]);
        $targetUser = $target->fetch();
        if (!$targetUser || $targetUser['role'] === 'admin') {
            $errors['status'] = $userId === (int) $_SESSION['user_id']
                ? 'You cannot deactivate your own Administrator account.'
                : 'Administrator accounts cannot be deactivated or deleted.';
        }
        if (!$errors) {
            $update = $pdo->prepare('UPDATE users SET status = :status, status_reason = :status_reason WHERE id = :id');
            $update->execute(['status' => $newStatus, 'status_reason' => $newStatus === 'inactive' ? $reason : null, 'id' => $userId]);
            $_SESSION['admin_user_success'] = 'Account status updated successfully.';
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
    }

    if ($action === 'delete_user') {
        $target = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $target->execute(['id' => $userId]);
        $targetUser = $target->fetch();
        if ($targetUser && $targetUser['role'] === 'admin') {
            $errors['delete'] = $userId === (int) $_SESSION['user_id']
                ? 'You cannot delete your own Administrator account.'
                : 'Administrator accounts cannot be deactivated or deleted.';
            $modal = 'delete-modal';
        } elseif (!$targetUser) {
            $errors['delete'] = 'User account was not found.';
            $modal = 'delete-modal';
        } elseif ($userId === (int) $_SESSION['user_id']) {
            $errors['delete'] = 'You cannot delete your own account while logged in.';
            $modal = 'delete-modal';
        } else {
            $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $userId]);
            $_SESSION['admin_user_success'] = 'User account deleted successfully.';
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
    }
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$roleFilter   = trim((string) ($_GET['role'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($roleFilter, ['admin', 'staff', 'rider', 'customer'], true)) {
    $roleFilter = '';
}
if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}
$hasFilters = $search !== '' || $roleFilter !== '' || $statusFilter !== '';

// Every LIKE needs its own bind key — native prepared statements reject a
// repeated named placeholder.
$sql = 'SELECT id, first_name, last_name, phone, email, role, status, status_reason, created_at FROM users WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (first_name LIKE :search_first OR last_name LIKE :search_last OR email LIKE :search_email OR phone LIKE :search_phone)';
    $term = '%' . $search . '%';
    $params = ['search_first' => $term, 'search_last' => $term, 'search_email' => $term, 'search_phone' => $term];
}
if ($roleFilter !== '') {
    $sql .= ' AND role = :role_filter';
    $params['role_filter'] = $roleFilter;
}
if ($statusFilter !== '') {
    $sql .= ' AND status = :status_filter';
    $params['status_filter'] = $statusFilter;
}
$sortMap = [
    'name_asc' => 'first_name ASC, last_name ASC',
    'name_desc' => 'first_name DESC, last_name DESC',
    'newest' => 'created_at DESC',
    'oldest' => 'created_at ASC',
    'role' => 'role ASC, first_name ASC',
    'status' => 'status ASC, first_name ASC',
];
$sort = array_key_exists($sort, $sortMap) ? $sort : 'newest';
$sql .= ' ORDER BY ' . $sortMap[$sort];
$list = $pdo->prepare($sql);
$list->execute($params);
$filteredUsers = $list->fetchAll();
$totalUsers = count($filteredUsers);
$perPage = $showOptions[$show];
$totalPages = $perPage > 0 ? max(1, (int) ceil($totalUsers / $perPage)) : 1;
$page = min($page, $totalPages);
$users = $perPage > 0 ? array_slice($filteredUsers, ($page - 1) * $perPage, $perPage) : $filteredUsers;
$showingStart = $totalUsers ? ($perPage > 0 ? (($page - 1) * $perPage) + 1 : 1) : 0;
$showingEnd = $totalUsers ? ($perPage > 0 ? min($page * $perPage, $totalUsers) : $totalUsers) : 0;
$queryParams = ['search' => $search, 'sort' => $sort, 'show' => $show, 'role' => $roleFilter, 'status' => $statusFilter];

// Headline numbers always describe every account, never the filtered view.
$userStats = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(role = 'customer'), 0) AS customers,
            COALESCE(SUM(role IN ('staff', 'rider')), 0) AS team,
            COALESCE(SUM(status = 'inactive'), 0) AS inactive
     FROM users"
)->fetch() ?: [];
$statTotals = [
    'total'     => (int) ($userStats['total'] ?? 0),
    'customers' => (int) ($userStats['customers'] ?? 0),
    'team'      => (int) ($userStats['team'] ?? 0),
    'inactive'  => (int) ($userStats['inactive'] ?? 0),
];

// Profile photos for the visible page only — blobs stay out of the main query.
$userPhotos = [];
if ($users) {
    $visibleIds = array_column($users, 'id');
    $photoPlaceholders = implode(',', array_fill(0, count($visibleIds), '?'));
    try {
        $photoStmt = $pdo->prepare("SELECT id, profile_data, profile_mime FROM users WHERE id IN ($photoPlaceholders)");
        $photoStmt->execute($visibleIds);
        foreach ($photoStmt->fetchAll() as $photoRow) {
            if (!empty($photoRow['profile_data']) && !empty($photoRow['profile_mime'])) {
                $userPhotos[(int) $photoRow['id']] = 'data:' . $photoRow['profile_mime'] . ';base64,' . base64_encode($photoRow['profile_data']);
            }
        }
    } catch (Throwable) {
        $userPhotos = [];
    }
}

/** Label + css suffix for an account role. */
function userRoleBadge(string $role): array
{
    switch ($role) {
        case 'admin':    return ['Admin', 'is-admin'];
        case 'staff':    return ['Staff', 'is-staff'];
        case 'rider':    return ['Rider', 'is-rider'];
        case 'customer': return ['Customer', 'is-customer'];
        default:         return [ucfirst($role), 'is-customer'];
    }
}

require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro users-page-intro">
    <div>
        <h2>User Management</h2>
        <p>Manage administrator, staff, rider, and customer accounts.</p>
    </div>
    <button class="admin-button primary" type="button" data-open-modal="add-user-modal"><i class="fa-solid fa-user-plus"></i> Add New User</button>
</section>

<?php if ($successMessage): ?><div class="admin-notice success" role="status"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
<?php if (!empty($errors['delete'])): ?><div class="admin-notice danger" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errors['delete']); ?></div><?php endif; ?>

<!-- ── Stats Row ── -->
<div class="voucher-stats-grid users-stats-row">
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-total"><i class="fa-solid fa-users"></i></div>
        <div><strong><?php echo $statTotals['total']; ?></strong><span>Total Users</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-used"><i class="fa-solid fa-basket-shopping"></i></div>
        <div><strong><?php echo $statTotals['customers']; ?></strong><span>Customers</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-active"><i class="fa-solid fa-people-group"></i></div>
        <div><strong><?php echo $statTotals['team']; ?></strong><span>Staff &amp; Riders</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon<?php echo $statTotals['inactive'] > 0 ? ' is-out' : ''; ?>"><i class="fa-solid fa-user-slash"></i></div>
        <div><strong><?php echo $statTotals['inactive']; ?></strong><span>Deactivated</span></div>
    </div>
</div>

<section class="panel users-panel">
    <form class="reports-toolbar" method="GET">
        <div class="reports-toolbar-left">
            <label class="reports-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email or phone…" aria-label="Search users" />
            </label>
            <select name="role" class="reports-sort" onchange="this.form.submit()" aria-label="Filter by account type">
                <option value="">All account types</option>
                <?php foreach (['admin' => 'Admin', 'staff' => 'Staff', 'rider' => 'Rider', 'customer' => 'Customer'] as $roleKey => $roleLabel): ?>
                    <option value="<?php echo $roleKey; ?>" <?php echo $roleFilter === $roleKey ? 'selected' : ''; ?>><?php echo $roleLabel; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="reports-sort" onchange="this.form.submit()" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Deactivated</option>
            </select>
        </div>
        <div class="reports-toolbar-right">
            <?php if ($hasFilters): ?>
                <a href="?<?php echo http_build_query(['sort' => $sort, 'show' => $show]); ?>" class="reports-chip is-clear"><i class="fa-solid fa-xmark"></i> Clear</a>
            <?php endif; ?>
            <span class="reports-count"><?php echo $totalUsers; ?> users</span>
            <select name="sort" class="reports-sort" onchange="this.form.submit()" aria-label="Sort users">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Sort: Newest</option>
                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Sort: Oldest</option>
                <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Sort: Name (A–Z)</option>
                <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Sort: Name (Z–A)</option>
                <option value="role" <?php echo $sort === 'role' ? 'selected' : ''; ?>>Sort: Account type</option>
                <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Sort: Status</option>
            </select>
            <select name="show" class="reports-sort" onchange="this.form.submit()" aria-label="Users per page">
                <?php foreach ($showOptions as $showKey => $showValue): ?>
                    <option value="<?php echo $showKey; ?>" <?php echo $show === (string) $showKey ? 'selected' : ''; ?>><?php echo $showKey === 'all' ? 'All' : $showKey; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($users): ?>
    <div class="table-wrap">
        <table class="admin-table users-table">
            <thead>
                <tr><th>User</th><th>Phone Number</th><th>Email</th><th>Account Type</th><th>Status</th><th>Created Date</th><th><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user):
                $userFullName = trim($user['first_name'] . ' ' . $user['last_name']);
                $userInitial = strtoupper(substr(trim((string) $user['first_name']), 0, 1)) ?: '?';
                list($roleLabel, $roleClass) = userRoleBadge((string) $user['role']);
                $isActive = $user['status'] === 'active';
                $photo = $userPhotos[(int) $user['id']] ?? '';
            ?>
                <tr<?php echo $isActive ? '' : ' class="is-inactive-row"'; ?>>
                    <td>
                        <div class="inventory-cell">
                            <span class="inventory-cell-thumb user-avatar<?php echo $isActive ? '' : ' is-inactive'; ?>">
                                <?php if ($photo !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="" />
                                <?php else: ?>
                                    <span aria-hidden="true"><?php echo htmlspecialchars($userInitial); ?></span>
                                <?php endif; ?>
                            </span>
                            <div class="inventory-cell-copy">
                                <strong><?php echo htmlspecialchars($userFullName); ?></strong>
                                <?php if (!empty($user['status_reason'])): ?>
                                    <small title="<?php echo htmlspecialchars($user['status_reason']); ?>"><?php echo htmlspecialchars(mb_strimwidth($user['status_reason'], 0, 40, '…')); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($user['phone']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><span class="user-role-badge <?php echo $roleClass; ?>"><?php echo $roleLabel; ?></span></td>
                    <td><span class="user-status-badge <?php echo $isActive ? 'is-active' : 'is-inactive'; ?>"><?php echo $isActive ? 'Active' : 'Deactivated'; ?></span></td>
                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                    <td>
                        <button class="dots-button" type="button" aria-label="Actions for <?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?>" aria-expanded="false"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                        <div class="action-menu" data-user-role="<?php echo htmlspecialchars($user['role']); ?>">
                            <button type="button" data-action="password" data-user-id="<?php echo (int) $user['id']; ?>" data-user-name="<?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?>"><i class="fa-solid fa-key"></i> Change Password</button>
                            <?php if ($user['role'] !== 'admin'): ?>
                                <button type="button" data-action="status" data-user-id="<?php echo (int) $user['id']; ?>" data-user-name="<?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?>" data-user-status="<?php echo htmlspecialchars($user['status']); ?>"><i class="fa-solid fa-user-gear"></i> Change Account Status</button>
                                <button type="button" class="danger-text" data-action="delete" data-user-id="<?php echo (int) $user['id']; ?>" data-user-name="<?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?>"><i class="fa-solid fa-trash-can"></i> Delete Account</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="vouchers-empty">
            <i class="fa-solid fa-users"></i>
            <p><?php echo $hasFilters ? 'No user accounts match the current filters.' : 'No user accounts yet.'; ?></p>
        </div>
    <?php endif; ?>

    <div class="list-footer">
        <span>Showing <?php echo $showingStart; ?>–<?php echo $showingEnd; ?> of <?php echo $totalUsers; ?> users</span>
        <?php if ($totalPages > 1 && $show !== 'all'): ?>
        <nav class="pagination" aria-label="User pages">
            <?php $previousParams = $queryParams; $previousParams['page'] = max(1, $page - 1); ?><a class="page-link <?php echo $page === 1 ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($previousParams); ?>"><i class="fa-solid fa-chevron-left"></i> Previous</a>
            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): $pageParams = $queryParams; $pageParams['page'] = $pageNumber; ?><a class="page-link <?php echo $pageNumber === $page ? 'current' : ''; ?>" href="?<?php echo http_build_query($pageParams); ?>"><?php echo $pageNumber; ?></a><?php endfor; ?>
            <?php $nextParams = $queryParams; $nextParams['page'] = min($totalPages, $page + 1); ?><a class="page-link <?php echo $page === $totalPages ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($nextParams); ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
        </nav>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Live search — filters as the user types instead of waiting for the button.
    const searchInput = document.querySelector('.reports-search input[type="search"]');
    if (!searchInput) return;
    let timer = null;
    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { searchInput.closest('form').submit(); }, 700);
    });
});
</script>

<div class="modal-backdrop <?php echo $modal ? 'is-open' : ''; ?>" data-modal-backdrop></div>
<div class="admin-modal <?php echo $modal === 'add-user-modal' ? 'is-open' : ''; ?>" id="add-user-modal" role="dialog" aria-modal="true" aria-labelledby="add-user-title"><div class="modal-heading"><div><span class="modal-kicker">Account Management</span><h2 id="add-user-title">Add New User</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" class="admin-form"><input type="hidden" name="action" value="create_user" /><div class="form-grid two"><div class="form-field"><label for="first_name">First Name</label><input id="first_name" name="first_name" value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>" required /><?php if (isset($errors['first_name'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['first_name']); ?></small><?php endif; ?></div><div class="form-field"><label for="last_name">Last Name</label><input id="last_name" name="last_name" value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>" required /><?php if (isset($errors['last_name'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['last_name']); ?></small><?php endif; ?></div><div class="form-field"><label for="phone">Phone Number</label><input id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>" required /><?php if (isset($errors['phone'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['phone']); ?></small><?php endif; ?></div><div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required /><?php if (isset($errors['email'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['email']); ?></small><?php endif; ?></div></div><div class="form-field"><label for="role">Select Role / Account Type</label><select id="role" name="role" required><option value="">Select Role / Account Type</option><option value="admin" <?php echo ($formData['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option><option value="staff" <?php echo ($formData['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option><option value="rider" <?php echo ($formData['role'] ?? '') === 'rider' ? 'selected' : ''; ?>>Rider</option><option value="customer" <?php echo ($formData['role'] ?? '') === 'customer' ? 'selected' : ''; ?>>Customer</option></select><?php if (isset($errors['role'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['role']); ?></small><?php endif; ?></div><div class="form-grid two"><div class="form-field"><label for="password">Password</label><div class="password-input"><input id="password" name="password" type="password" required /><button type="button" data-toggle-password="password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><?php if (isset($errors['password'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['password']); ?></small><?php endif; ?></div><div class="form-field"><label for="confirm_password">Confirm Password</label><div class="password-input"><input id="confirm_password" name="confirm_password" type="password" required /><button type="button" data-toggle-password="confirm_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><?php if (isset($errors['confirm_password'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['confirm_password']); ?></small><?php endif; ?></div></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit"><i class="fa-solid fa-user-plus"></i> Create User</button></div></form></div>

<div class="admin-modal compact <?php echo $modal === 'password-modal' ? 'is-open' : ''; ?>" id="password-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Security</span><h2>Change Password</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" class="admin-form"><input type="hidden" name="action" value="change_password" /><input type="hidden" name="user_id" class="modal-user-id" value="<?php echo (int) $userId; ?>" /><div class="form-field"><label for="new_password">New Password</label><div class="password-input"><input id="new_password" name="new_password" type="password" required /><button type="button" data-toggle-password="new_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><?php if (isset($errors['new_password'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['new_password']); ?></small><?php endif; ?></div><div class="form-field"><label for="password_confirm">Confirm Password</label><div class="password-input"><input id="password_confirm" name="confirm_password" type="password" required /><button type="button" data-toggle-password="password_confirm" aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><?php if (isset($errors['password_confirm'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['password_confirm']); ?></small><?php endif; ?></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit"><i class="fa-solid fa-key"></i> Update Password</button></div></form></div>

<div class="admin-modal compact <?php echo $modal === 'status-modal' ? 'is-open' : ''; ?>" id="status-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Account Management</span><h2>Update Account Status</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" class="admin-form"><input type="hidden" name="action" value="update_status" /><input type="hidden" name="user_id" class="modal-user-id" value="<?php echo (int) $userId; ?>" /><div class="form-field"><label for="account-status">Account Status</label><select id="account-status" name="status"><option value="active">Active</option><option value="inactive">Deactivated</option></select></div><div class="form-field"><label for="status-reason">Reason</label><textarea id="status-reason" name="reason" placeholder="Enter reason for changing the account status..."></textarea><?php if (isset($errors['reason'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['reason']); ?></small><?php endif; ?></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit"><i class="fa-solid fa-check"></i> Update Status</button></div></form></div>

<div class="admin-modal compact warning-modal <?php echo $modal === 'delete-modal' ? 'is-open' : ''; ?>" id="delete-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker warning">Permanent action</span><h2>Delete Account</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>Are you sure you want to delete this account? This action is irreversible.</p><form method="POST" class="admin-form"><input type="hidden" name="action" value="delete_user" /><input type="hidden" name="user_id" class="modal-user-id" value="<?php echo (int) $userId; ?>" /><div class="form-field"><label for="delete-confirmation">Confirmation</label><input id="delete-confirmation" type="text" placeholder="Type 'Delete' to confirm" autocomplete="off" /></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button danger-button" type="submit" disabled><i class="fa-solid fa-trash-can"></i> Delete Account</button></div></form></div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
