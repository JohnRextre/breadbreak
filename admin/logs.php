<?php
/**
 * Admin System Logs — account-level audit across every role.
 *
 * Each portal shows its own slice of user_activity_log (rider activity, the
 * customer Account Activities panel, the staff Activity History); this page is
 * the whole picture: who signed in and signed out, what each account changed,
 * plus a derived "Account created" event per user — searchable, filterable by
 * role and event type, and sortable.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$pageTitle = 'System Logs';
$activePage = 'logs';

$pdo = getDatabaseConnection();
ensureUserActivityLog($pdo);

// ─────────────────────────────────────────────────────────────────────────────
// Filters
// ─────────────────────────────────────────────────────────────────────────────
$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = (string) ($_GET['role'] ?? 'all');
if (!in_array($roleFilter, ['all', 'admin', 'staff', 'rider', 'customer'], true)) {
    $roleFilter = 'all';
}
$typeFilter = (string) ($_GET['type'] ?? 'all');
if (!in_array($typeFilter, ['all', 'signins', 'signouts', 'changes'], true)) {
    $typeFilter = 'all';
}
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}

$roleNames = [
    'admin' => 'Admin',
    'staff' => 'Staff',
    'rider' => 'Rider',
    'customer' => 'Customer',
];

// ─────────────────────────────────────────────────────────────────────────────
// Headline numbers — these always describe every account, never the filtered view.
// ─────────────────────────────────────────────────────────────────────────────
$accountTotal = 0;
$logTotal = 0;
$signinsToday = 0;
try {
    $accountTotal = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable) {
}
try {
    $logTotal = (int) $pdo->query('SELECT COUNT(*) FROM user_activity_log')->fetchColumn();
} catch (Throwable) {
}
try {
    $signinsToday = (int) $pdo->query("SELECT COUNT(*) FROM user_activity_log WHERE action = 'login' AND created_at >= CURDATE()")->fetchColumn();
} catch (Throwable) {
}

// ─────────────────────────────────────────────────────────────────────────────
// Build the event list: one "Account created" per account + every logged action.
// ─────────────────────────────────────────────────────────────────────────────
$activityLabels = [
    'login' => ['Signed in', 'fa-right-to-bracket'],
    'logout' => ['Signed out', 'fa-right-from-bracket'],
    'password_changed' => ['Password changed', 'fa-lock'],
    'photo_updated' => ['Profile photo updated', 'fa-camera'],
    'photo_removed' => ['Profile photo removed', 'fa-camera'],
    'profile_updated' => ['Profile details updated', 'fa-id-card'],
];

$events = [];
try {
    $accountRows = $pdo->query('SELECT first_name, last_name, role, email, created_at FROM users ORDER BY created_at DESC')->fetchAll();
} catch (Throwable) {
    $accountRows = [];
}
foreach ($accountRows as $account) {
    $fullName = trim($account['first_name'] . ' ' . $account['last_name']);
    $events[] = [
        'action' => 'account_created',
        'category' => 'account',
        'icon' => 'fa-user-plus',
        'title' => 'Account created',
        'actor' => $fullName !== '' ? $fullName : 'Unknown account',
        'role' => (string) $account['role'],
        'email' => (string) $account['email'],
        'detail' => ucfirst((string) $account['role']) . ' account registered',
        'at' => (string) $account['created_at'],
    ];
}

try {
    // Newest 500 log rows — the cap keeps the page responsive if the log grows
    // large; the count line tells the user to refine the search beyond it.
    $statement = $pdo->prepare(
        'SELECT l.action, l.detail, l.created_at, u.first_name, u.last_name, u.role, u.email
           FROM user_activity_log l
           LEFT JOIN users u ON u.id = l.user_id
          ORDER BY l.id DESC
          LIMIT 500'
    );
    $statement->execute();
    $logRows = $statement->fetchAll();
} catch (Throwable) {
    $logRows = [];
}
foreach ($logRows as $logRow) {
    $action = (string) $logRow['action'];
    [$title, $icon] = $activityLabels[$action] ?? [ucwords(str_replace('_', ' ', $action)), 'fa-circle-info'];
    $fullName = trim(($logRow['first_name'] ?? '') . ' ' . ($logRow['last_name'] ?? ''));
    $events[] = [
        'action' => $action,
        'category' => in_array($action, ['login', 'logout'], true) ? 'session' : 'account',
        'icon' => $icon,
        'title' => $title,
        'actor' => $fullName !== '' ? $fullName : 'Deleted account',
        'role' => (string) ($logRow['role'] ?? ''),
        'email' => (string) ($logRow['email'] ?? ''),
        'detail' => (string) ($logRow['detail'] ?? ''),
        'at' => (string) $logRow['created_at'],
    ];
}

$totalActivities = count($events);

// ─────────────────────────────────────────────────────────────────────────────
// Filter → sort → cap
// ─────────────────────────────────────────────────────────────────────────────
if ($roleFilter !== 'all') {
    $events = array_values(array_filter($events, fn (array $event) => strtolower($event['role']) === $roleFilter));
}
if ($typeFilter === 'signins') {
    $events = array_values(array_filter($events, fn (array $event) => $event['action'] === 'login'));
} elseif ($typeFilter === 'signouts') {
    $events = array_values(array_filter($events, fn (array $event) => $event['action'] === 'logout'));
} elseif ($typeFilter === 'changes') {
    $events = array_values(array_filter($events, fn (array $event) => $event['category'] === 'account'));
}
if ($q !== '') {
    $needle = mb_strtolower($q);
    $events = array_values(array_filter($events, function (array $event) use ($needle) {
        $haystack = mb_strtolower($event['title'] . ' ' . $event['actor'] . ' ' . $event['email'] . ' ' . $event['detail']);
        return mb_strpos($haystack, $needle) !== false;
    }));
}
usort($events, function (array $left, array $right) use ($sort) {
    $leftTime = strtotime($left['at']) ?: 0;
    $rightTime = strtotime($right['at']) ?: 0;
    return $sort === 'oldest' ? $leftTime <=> $rightTime : $rightTime <=> $leftTime;
});

$shownActivities = count($events);
$truncated = count($events) > 200;
$events = array_slice($events, 0, 200);

// ─────────────────────────────────────────────────────────────────────────────
// Group by day (Today / Yesterday / date) — same rhythm as the other feeds.
// ─────────────────────────────────────────────────────────────────────────────
$todayKey = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
$days = [];
foreach ($events as $event) {
    $timestamp = strtotime($event['at']);
    $dayKey = $timestamp !== false ? date('Y-m-d', $timestamp) : 'unknown';
    if (!isset($days[$dayKey])) {
        $dayLabel = 'Unknown date';
        if ($timestamp !== false) {
            if ($dayKey === $todayKey) $dayLabel = 'Today';
            elseif ($dayKey === $yesterdayKey) $dayLabel = 'Yesterday';
            else $dayLabel = date('D, M j, Y', $timestamp);
        }
        $days[$dayKey] = ['label' => $dayLabel, 'items' => []];
    }
    $event['time'] = $timestamp !== false ? date('g:i A', $timestamp) : '';
    $days[$dayKey]['items'][] = $event;
}

$hasFilters = $q !== '' || $roleFilter !== 'all' || $typeFilter !== 'all' || $sort !== 'newest';
$typeLabels = [
    'all' => 'account activity',
    'signins' => 'sign-ins',
    'signouts' => 'sign-outs',
    'changes' => 'account changes',
];

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro users-page-intro">
    <div>
        <h2>System Logs</h2>
        <p>Who signed in and what changed across every account — admin, staff, rider and customer.</p>
    </div>
    <a class="admin-button secondary" href="<?php echo BASE_URL; ?>/admin/users.php" style="height:44px; padding:0 20px; border-radius:999px; font-weight:800; display:inline-flex; align-items:center; gap:7px;">
        <i class="fa-solid fa-users"></i> User Management
    </a>
</section>

<section class="orders-summary-grid">
    <article class="summary-card">
        <div class="summary-top"><span>Accounts</span><div class="summary-icon" style="background: rgba(123,82,59,.1); color: var(--admin-brown);"><i class="fa-solid fa-users"></i></div></div>
        <div class="summary-value" style="color: var(--admin-brown);"><?php echo $accountTotal; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top"><span>Total events</span><div class="summary-icon" style="background: rgba(32,82,168,.1); color: #2052a8;"><i class="fa-solid fa-list"></i></div></div>
        <div class="summary-value" style="color: #2052a8;"><?php echo $logTotal + $accountTotal; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top"><span>Sign-ins today</span><div class="summary-icon" style="background: rgba(31,157,99,.1); color: #1a6645;"><i class="fa-solid fa-right-to-bracket"></i></div></div>
        <div class="summary-value" style="color: #1a6645;"><?php echo $signinsToday; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top"><span>Showing</span><div class="summary-icon" style="background: rgba(190,118,43,.1); color: var(--admin-orange);"><i class="fa-solid fa-filter"></i></div></div>
        <div class="summary-value" style="color: var(--admin-orange);"><?php echo $shownActivities; ?></div>
    </article>
</section>

<section class="panel">
    <form method="GET" class="orders-filter-bar history-filter-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" placeholder="Search name, email or event..." value="<?php echo htmlspecialchars($q); ?>" />
        </div>

        <div class="filter-actions">
            <div class="select-control" style="margin:0;">
                <select name="role" onchange="this.form.submit()" aria-label="Role">
                    <option value="all">All roles</option>
                    <?php foreach ($roleNames as $roleKey => $roleName): ?>
                        <option value="<?php echo $roleKey; ?>" <?php echo $roleFilter === $roleKey ? 'selected' : ''; ?>><?php echo $roleName; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="select-control" style="margin:0;">
                <select name="type" onchange="this.form.submit()" aria-label="Event type">
                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All activity</option>
                    <option value="signins" <?php echo $typeFilter === 'signins' ? 'selected' : ''; ?>>Sign-ins</option>
                    <option value="signouts" <?php echo $typeFilter === 'signouts' ? 'selected' : ''; ?>>Sign-outs</option>
                    <option value="changes" <?php echo $typeFilter === 'changes' ? 'selected' : ''; ?>>Account changes</option>
                </select>
            </div>
            <div class="select-control" style="margin:0;">
                <select name="sort" onchange="this.form.submit()" aria-label="Sort">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                </select>
            </div>
            <button type="submit" class="admin-button primary" style="height:42px; border-radius:8px; padding:0 18px;">
                <i class="fa-solid fa-filter"></i> Apply
            </button>
            <?php if ($hasFilters): ?>
                <a href="logs.php" class="admin-button secondary" style="height:42px; border-radius:8px; padding:0 16px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$days): ?>
        <div class="empty-state" style="text-align:center; padding:60px 20px;">
            <div style="width:64px; height:64px; margin:0 auto 16px; border-radius:50%; background:#faf7f4; display:flex; align-items:center; justify-content:center; font-size:26px; color:var(--admin-muted);">
                <i class="fa-solid <?php echo $q !== '' ? 'fa-magnifying-glass' : 'fa-clock-rotate-left'; ?>"></i>
            </div>
            <h3 style="color:var(--admin-brown-dark); margin:0 0 6px; font-size:16px;">
                <?php echo $hasFilters ? 'No ' . htmlspecialchars($typeLabels[$typeFilter]) . ' match those filters' : 'No system activity recorded yet'; ?>
            </h3>
            <p style="color:var(--admin-muted); font-size:13px; margin:0;">
                <?php echo $hasFilters
                    ? 'Try a different keyword, switch the role or event type, or reset the filters.'
                    : 'Sign-ins, sign-outs and account changes will appear here automatically.'; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="audit-feed">
            <?php foreach ($days as $day): ?>
                <div class="audit-day"><?php echo htmlspecialchars($day['label']); ?></div>
                <ul class="audit-list">
                    <?php foreach ($day['items'] as $event): ?>
                        <?php
                        // The role chip already says "Customer account" — don't repeat it as a detail line.
                        $detailText = (string) $event['detail'];
                        if ($detailText !== '' && strcasecmp($detailText, $event['role'] . ' account') === 0) {
                            $detailText = '';
                        }
                        ?>
                        <li class="audit-item">
                            <span class="audit-icon"><i class="fa-solid <?php echo $event['icon']; ?>"></i></span>
                            <div class="audit-copy">
                                <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                <small>
                                    <?php if ($event['role'] !== ''): ?>
                                        <span class="role-badge role-<?php echo htmlspecialchars(strtolower($event['role'])); ?>"><?php echo htmlspecialchars($roleNames[$event['role']] ?? ucfirst($event['role'])); ?></span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($event['actor']); ?><?php if ($event['email'] !== ''): ?> · <?php echo htmlspecialchars($event['email']); ?><?php endif; ?>
                                </small>
                                <?php if ($detailText !== ''): ?>
                                    <small><?php echo htmlspecialchars($detailText); ?></small>
                                <?php endif; ?>
                            </div>
                            <time class="audit-time"><?php echo htmlspecialchars($event['time']); ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
        <p class="orders-result-count">
            Showing <?php echo $shownActivities; ?> of <?php echo $totalActivities; ?> activities<?php echo $hasFilters ? ' (filtered)' : ''; ?><?php echo $truncated ? ' · only the first 200 matches are listed — refine your search to narrow it down.' : ''; ?>
        </p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
