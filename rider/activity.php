<?php
/**
 * Rider Activity History — one combined feed of:
 *
 *  - Account audit: created, signed in, signed out, password/photo changes
 *    (source: user_activity_log, written best-effort by login/logout/account).
 *  - Delivery milestones: assigned trips, cash collected, deliveries completed
 *    (source: order_status_history — the same audit trail staff sees per order).
 *
 * Reached from the topbar's "Activity History" link, right beside Delivery history.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('rider');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$pdo = getDatabaseConnection();
$riderId = (int) ($_SESSION['user_id'] ?? 0);

$accountStatement = $pdo->prepare(
    'SELECT first_name, last_name, created_at FROM users WHERE id = :id AND role = :role LIMIT 1'
);
$accountStatement->execute(['id' => $riderId, 'role' => 'rider']);
$account = $accountStatement->fetch() ?: [];
if (!$account) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'account', 'delivery'], true)) {
    $filter = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}

// ── Account events ─────────────────────────────────────────────────────────
ensureUserActivityLog($pdo);
$accountLog = [];
try {
    $logStatement = $pdo->prepare(
        'SELECT action, detail, created_at
           FROM user_activity_log
          WHERE user_id = :uid
          ORDER BY id DESC
          LIMIT 200'
    );
    $logStatement->execute(['uid' => $riderId]);
    $accountLog = $logStatement->fetchAll();
} catch (Throwable) {
    // Table not created yet (no audit written on this install) — feed still
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
        'kind' => 'account',
        'icon' => 'fa-user-plus',
        'title' => 'Account created',
        'detail' => 'Rider account registered',
        'note' => null,
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
        'kind' => 'account',
        'icon' => $icon,
        'title' => $title,
        'detail' => $logRow['detail'] !== null ? (string) $logRow['detail'] : null,
        'note' => null,
        'at' => (string) $logRow['created_at'],
    ];
}

// ── Delivery events ────────────────────────────────────────────────────────
// The rider's own actions on any trip they worked (actor match) plus the trip
// assignment that put the order on their dashboard. Assignment rows carry the
// rider's name in the note ("<name> assigned — …"), which keeps stale rows from
// a later reassignment from showing up under the wrong rider.
$riderFullName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));
$deliveryStatement = $pdo->prepare(
    "SELECT h.from_status, h.to_status, h.actor_role, h.note, h.created_at, o.reference_id
       FROM order_status_history h
       JOIN orders o ON o.id = h.order_id
      WHERE (h.actor_id = :actor AND h.actor_role = 'rider')
         OR (o.rider_id = :assigned_to AND h.actor_role = 'staff'
             AND h.from_status = 'processing' AND h.to_status = 'out_for_delivery'
             AND h.note LIKE :assigned_prefix)
      ORDER BY h.created_at DESC, h.id DESC
      LIMIT 200"
);
$deliveryStatement->execute([
    'actor' => $riderId,
    'assigned_to' => $riderId,
    'assigned_prefix' => $riderFullName . ' assigned%',
]);

foreach ($deliveryStatement->fetchAll() as $deliveryRow) {
    $note = $deliveryRow['note'] !== null ? trim((string) $deliveryRow['note']) : '';
    if ($deliveryRow['actor_role'] === 'rider') {
        if ($deliveryRow['to_status'] === 'completed') {
            $title = 'Delivery completed';
            $icon = 'fa-circle-check';
        } elseif (stripos($note, 'cash collected') === 0) {
            $title = 'Cash collected';
            $icon = 'fa-coins';
        } else {
            $title = 'Delivery update';
            $icon = 'fa-bicycle';
        }
    } else {
        $title = 'Assigned to a delivery';
        $icon = 'fa-box';
    }

    $events[] = [
        'kind' => 'delivery',
        'icon' => $icon,
        'title' => $title,
        'detail' => 'Order #' . $deliveryRow['reference_id'],
        'note' => $note !== '' ? $note : null,
        'at' => (string) $deliveryRow['created_at'],
    ];
}

$totalActivities = count($events);

// Search — matches event titles, order references and audit notes.
if ($q !== '') {
    $needle = mb_strtolower($q);
    $events = array_values(array_filter(
        $events,
        function (array $event) use ($needle) {
            $haystack = mb_strtolower(implode(' ', array_filter([$event['title'], $event['detail'], $event['note']])));
            return $haystack !== '' && mb_stripos($haystack, $needle) !== false;
        }
    ));
}

// Pill counts reflect the current search, so each number matches what a click
// would actually show.
$accountCount = count(array_filter($events, fn (array $event) => $event['kind'] === 'account'));
$deliveryCount = count($events) - $accountCount;

$events = array_values(array_filter(
    $events,
    fn (array $event) => $filter === 'all' || $event['kind'] === $filter
));

usort(
    $events,
    $sort === 'oldest'
        ? fn (array $a, array $b) => strcmp($a['at'], $b['at'])
        : fn (array $a, array $b) => strcmp($b['at'], $a['at'])
);

$shownActivities = count($events);
$truncated = count($events) > 150;
$events = array_slice($events, 0, 150);

// Group into day headings (Today / Yesterday / Weekday date).
$days = [];
foreach ($events as $event) {
    $timestamp = strtotime($event['at']);
    $dayKey = $timestamp !== false ? date('Y-m-d', $timestamp) : 'unknown';
    if (!isset($days[$dayKey])) {
        if ($dayKey === date('Y-m-d')) {
            $dayLabel = 'Today';
        } elseif ($dayKey === date('Y-m-d', strtotime('-1 day'))) {
            $dayLabel = 'Yesterday';
        } else {
            $dayLabel = $timestamp !== false ? date('D, M j, Y', $timestamp) : 'Unknown date';
        }
        $days[$dayKey] = ['label' => $dayLabel, 'items' => []];
    }
    $days[$dayKey]['items'][] = ['event' => $event, 'time' => $timestamp !== false ? date('g:i A', $timestamp) : ''];
}

// Pill links keep the current search + sort so refining never resets the form.
$pillLinks = [];
foreach (['all', 'account', 'delivery'] as $pillFilter) {
    $pillParams = ['filter' => $pillFilter];
    if ($q !== '') {
        $pillParams['q'] = $q;
    }
    if ($sort !== 'newest') {
        $pillParams['sort'] = $sort;
    }
    $pillLinks[$pillFilter] = '?' . http_build_query($pillParams);
}

$pageTitle = 'Activity History | BreadBreak';
require __DIR__ . '/../includes/rider_header.php';
?>

<section class="rider-hero is-history">
    <div class="rider-hero-copy">
        <span class="rider-chip">Activity</span>
        <h1>Activity History</h1>
        <p>Every sign-in, account change and delivery milestone for your rider account — search or sort to find one fast.</p>
        <a class="rider-back" href="<?php echo BASE_URL; ?>/rider/dashboard.php">
            <i class="fa-solid fa-arrow-left"></i> Back to dashboard
        </a>
    </div>
</section>

<form class="rider-tools" method="get" action="">
    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>" />
    <div class="rider-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>"
               placeholder="Search events, order #, notes…" autocomplete="off" />
    </div>
    <label class="rider-sort">
        <span>Sort</span>
        <select name="sort" onchange="this.form.submit();">
            <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest first</option>
            <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest first</option>
        </select>
    </label>
    <button class="rider-btn primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    <span class="rider-tools-count">
        <?php echo $shownActivities; ?> of <?php echo $totalActivities; ?> activit<?php echo $totalActivities === 1 ? 'y' : 'ies'; ?>
    </span>
</form>

<nav class="rider-filter" aria-label="Filter activity">
    <a href="<?php echo $pillLinks['all']; ?>" class="<?php echo $filter === 'all' ? 'is-on' : ''; ?>">
        <i class="fa-solid fa-list"></i> All <span><?php echo $accountCount + $deliveryCount; ?></span>
    </a>
    <a href="<?php echo $pillLinks['account']; ?>" class="<?php echo $filter === 'account' ? 'is-on' : ''; ?>">
        <i class="fa-solid fa-user-shield"></i> Account <span><?php echo $accountCount; ?></span>
    </a>
    <a href="<?php echo $pillLinks['delivery']; ?>" class="<?php echo $filter === 'delivery' ? 'is-on' : ''; ?>">
        <i class="fa-solid fa-bicycle"></i> Deliveries <span><?php echo $deliveryCount; ?></span>
    </a>
</nav>

<?php if ($truncated): ?>
    <div class="rider-note-bar">
        <i class="fa-solid fa-circle-info"></i>
        Showing the <?php echo $sort === 'oldest' ? 'earliest' : 'most recent'; ?> 150 activities — refine your search to see the rest.
    </div>
<?php endif; ?>

<?php if (!$days): ?>
    <div class="rider-empty">
        <i class="fa-solid <?php echo $q !== '' ? 'fa-magnifying-glass' : 'fa-clock-rotate-left'; ?>"></i>
        <h2><?php if ($q !== '') {
            echo 'No activities match your search';
        } elseif ($filter === 'delivery') {
            echo 'No delivery activity yet';
        } else {
            echo 'No activity recorded yet';
        } ?></h2>
        <p><?php if ($q !== '') {
            echo 'Try another keyword — search covers event names, order numbers and notes.';
        } elseif ($filter === 'delivery') {
            echo 'Assigned trips, cash collections and completed deliveries show up here.';
        } else {
            echo 'Sign-ins and account changes will appear here as soon as they happen.';
        } ?></p>
    </div>
<?php else: ?>
    <?php foreach ($days as $day): ?>
        <div class="rider-act-day"><span><?php echo htmlspecialchars($day['label']); ?></span></div>
        <ul class="rider-act-list">
            <?php foreach ($day['items'] as $item): $event = $item['event']; ?>
                <li class="rider-act-item is-<?php echo htmlspecialchars($event['kind']); ?>">
                    <span class="rider-act-icon"><i class="fa-solid <?php echo htmlspecialchars($event['icon']); ?>"></i></span>
                    <div class="rider-act-body">
                        <div class="rider-act-top">
                            <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                            <time><?php echo htmlspecialchars($item['time']); ?></time>
                        </div>
                        <?php if ($event['detail']): ?>
                            <p class="rider-act-detail"><?php echo htmlspecialchars($event['detail']); ?></p>
                        <?php endif; ?>
                        <?php if ($event['note']): ?>
                            <small class="rider-act-note"><?php echo htmlspecialchars($event['note']); ?></small>
                        <?php endif; ?>
                    </div>
                    <span class="rider-act-kind"><?php echo $event['kind'] === 'account' ? 'Account' : 'Delivery'; ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/rider_footer.php'; ?>
