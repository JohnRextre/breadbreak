<?php
/**
 * Staff Activity History — sign-ins, sign-outs and account changes.
 *
 * Account-level audit for the signed-in staff member (mirrors the rider's
 * activity.php). Inventory/stock changes live in their own trail on
 * staff/inventory-history.php, so this feed stays about the account itself.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$pageTitle = 'Activity History';
$activePage = 'activity';

$pdo = getDatabaseConnection();
$staffId = (int) ($_SESSION['user_id'] ?? 0);
ensureUserActivityLog($pdo);

$account = [];
try {
    $accountStatement = $pdo->prepare('SELECT first_name, last_name, created_at FROM users WHERE id = :id AND role = :role LIMIT 1');
    $accountStatement->execute(['id' => $staffId, 'role' => 'staff']);
    $account = $accountStatement->fetch() ?: [];
} catch (Throwable) {
    // Profile row unreadable — the feed still renders whatever the log holds.
}

$accountLog = [];
try {
    $logStatement = $pdo->prepare(
        'SELECT action, detail, created_at
           FROM user_activity_log
          WHERE user_id = :uid
          ORDER BY id DESC
          LIMIT 200'
    );
    $logStatement->execute(['uid' => $staffId]);
    $accountLog = $logStatement->fetchAll();
} catch (Throwable) {
    // Table not created yet (no audit written on this install) — the feed still
    // renders the "Account created" milestone below.
}

$activityLabels = [
    'login' => ['Signed in', 'fa-right-to-bracket'],
    'logout' => ['Signed out', 'fa-right-from-bracket'],
    'password_changed' => ['Password changed', 'fa-lock'],
    'photo_updated' => ['Profile photo updated', 'fa-camera'],
    'photo_removed' => ['Profile photo removed', 'fa-camera'],
    'profile_updated' => ['Profile details updated', 'fa-id-card'],
];

$events = [];

if (!empty($account['created_at'])) {
    $events[] = [
        'icon' => 'fa-user-plus',
        'title' => 'Account created',
        'detail' => 'Staff account registered',
        'at' => (string) $account['created_at'],
    ];
}

foreach ($accountLog as $logRow) {
    $action = (string) $logRow['action'];
    [$title, $icon] = $activityLabels[$action] ?? [
        ucwords(str_replace('_', ' ', $action)),
        'fa-circle-info',
    ];
    $events[] = [
        'icon' => $icon,
        'title' => $title,
        'detail' => $logRow['detail'] !== null ? (string) $logRow['detail'] : null,
        'at' => (string) $logRow['created_at'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Search + sort
// ─────────────────────────────────────────────────────────────────────────────
$q = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}

$totalActivities = count($events);
if ($q !== '') {
    $needle = mb_strtolower($q);
    $events = array_values(array_filter(
        $events,
        function (array $event) use ($needle) {
            $haystack = mb_strtolower(implode(' ', array_filter([$event['title'], $event['detail']])));
            return $haystack !== '' && mb_stripos($haystack, $needle) !== false;
        }
    ));
}

usort(
    $events,
    $sort === 'oldest'
        ? fn (array $a, array $b) => strcmp($a['at'], $b['at'])
        : fn (array $a, array $b) => strcmp($b['at'], $a['at'])
);

$shownActivities = count($events);
$truncated = count($events) > 200;
$events = array_slice($events, 0, 200);

// ─────────────────────────────────────────────────────────────────────────────
// Group by day (Today / Yesterday / date) — same rhythm as the rider feed.
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
    $days[$dayKey]['items'][] = [
        'event' => $event,
        'time' => $timestamp !== false ? date('g:i A', $timestamp) : '',
    ];
}

$hasFilters = $q !== '' || $sort !== 'newest';

require __DIR__ . '/../includes/staff_header.php';
?>

<section class="page-intro users-page-intro inventory-page-intro">
    <div>
        <span class="eyebrow">Audit Trail</span>
        <h2>Activity History</h2>
        <p>Sign-ins, sign-outs and account changes on your staff account. Stock changes live in Inventory History.</p>
    </div>
    <a class="admin-button secondary" href="<?php echo BASE_URL; ?>/staff/profile.php" style="height:44px; padding:0 20px; border-radius:999px; font-weight:800; display:inline-flex; align-items:center; gap:7px;">
        <i class="fa-solid fa-circle-user"></i> My Account
    </a>
</section>

<section class="panel">
    <form method="GET" class="orders-filter-bar history-filter-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" placeholder="Search events or details..." value="<?php echo htmlspecialchars($q); ?>" />
        </div>

        <div class="filter-actions">
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
                <a href="activity.php" class="admin-button secondary" style="height:42px; border-radius:8px; padding:0 16px;">
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
                <?php echo $q !== '' ? 'No activities match your search' : 'No activity recorded yet'; ?>
            </h3>
            <p style="color:var(--admin-muted); font-size:13px; margin:0;">
                <?php echo $q !== ''
                    ? 'Try another keyword — search covers event names and details.'
                    : 'Sign-ins and account changes will appear here as soon as they happen.'; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="staff-activity-feed">
            <?php foreach ($days as $day): ?>
                <div class="staff-activity-day"><?php echo htmlspecialchars($day['label']); ?></div>
                <ul class="staff-activity-list">
                    <?php foreach ($day['items'] as $dayItem): $event = $dayItem['event']; ?>
                        <li class="staff-activity-item">
                            <span class="staff-activity-icon"><i class="fa-solid <?php echo $event['icon']; ?>"></i></span>
                            <div class="staff-activity-copy">
                                <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                <?php if (!empty($event['detail'])): ?>
                                    <small><?php echo htmlspecialchars($event['detail']); ?></small>
                                <?php endif; ?>
                            </div>
                            <time class="staff-activity-time"><?php echo htmlspecialchars($dayItem['time']); ?></time>
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

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
