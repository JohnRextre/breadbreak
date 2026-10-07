<?php
/**
 * Staff Inventory History — every catalogue and stock change.
 *
 * staff/inventory.php shows what the catalogue looks like now; this is how it
 * got there: items added/edited/deleted, prices and quantities adjusted, menus
 * and service sizes managed — who did it and when.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/inventory_log.php';

$pageTitle = 'Inventory History';
$activePage = 'inventory-history';

$pdo = getDatabaseConnection();
ensureInventoryActivityLog($pdo);

// ─────────────────────────────────────────────────────────────────────────────
// Filters
// ─────────────────────────────────────────────────────────────────────────────
$q = trim((string) ($_GET['q'] ?? ''));
$kind = (string) ($_GET['kind'] ?? 'all');
if (!in_array($kind, ['all', 'items', 'menus', 'stock'], true)) {
    $kind = 'all';
}
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}

$activityRows = [];
$totalActivities = 0;
try {
    $totalActivities = (int) $pdo->query('SELECT COUNT(*) FROM inventory_activity_log')->fetchColumn();

    $where = [];
    $params = [];
    if ($kind === 'items') {
        $where[] = "entity = 'item'";
    } elseif ($kind === 'menus') {
        $where[] = "entity = 'menu'";
    } elseif ($kind === 'stock') {
        $where[] = "action = 'stock_reduced'";
    }
    if ($q !== '') {
        $where[] = '(entity_name LIKE :q_name OR detail LIKE :q_detail OR actor_name LIKE :q_actor OR action LIKE :q_action)';
        $term = '%' . $q . '%';
        $params['q_name'] = $term;
        $params['q_detail'] = $term;
        $params['q_actor'] = $term;
        $params['q_action'] = $term;
    }
    $sql = 'SELECT * FROM inventory_activity_log'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ($sort === 'oldest' ? ' ORDER BY created_at ASC, id ASC' : ' ORDER BY created_at DESC, id DESC');
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $activityRows = $statement->fetchAll();
} catch (Throwable) {
    // Log table unavailable on this install — the empty state below covers it.
}

$inventoryLabels = [
    'item_created' => ['Item added', 'fa-plus'],
    'item_updated' => ['Item updated', 'fa-pen'],
    'item_deleted' => ['Item deleted', 'fa-trash-can'],
    'stock_reduced' => ['Stock reduced', 'fa-minus'],
    'menu_created' => ['Menu added', 'fa-folder-plus'],
    'menu_updated' => ['Menu updated', 'fa-pen'],
    'menu_deleted' => ['Menu deleted', 'fa-trash-can'],
    'size_added' => ['Service size added', 'fa-ruler'],
];

$shownActivities = count($activityRows);
$truncated = count($activityRows) > 200;
$activityRows = array_slice($activityRows, 0, 200);

// ─────────────────────────────────────────────────────────────────────────────
// Group by day (Today / Yesterday / date) — same rhythm as the Activity feed.
// ─────────────────────────────────────────────────────────────────────────────
$todayKey = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
$days = [];
foreach ($activityRows as $row) {
    $timestamp = strtotime((string) $row['created_at']);
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
    $action = (string) $row['action'];
    [$title, $icon] = $inventoryLabels[$action] ?? [
        ucwords(str_replace('_', ' ', $action)),
        'fa-circle-info',
    ];
    $days[$dayKey]['items'][] = [
        'row' => $row,
        'title' => $title,
        'icon' => $icon,
        'time' => $timestamp !== false ? date('g:i A', $timestamp) : '',
    ];
}

$hasFilters = $q !== '' || $kind !== 'all' || $sort !== 'newest';
$kindLabels = [
    'all' => 'inventory history',
    'items' => 'inventory item changes',
    'menus' => 'menu and size changes',
    'stock' => 'stock changes',
];

require __DIR__ . '/../includes/staff_header.php';
?>

<section class="page-intro users-page-intro inventory-page-intro">
    <div>
        <span class="eyebrow">Audit Trail</span>
        <h2>Inventory History</h2>
        <p>Every item, menu and stock change — who made it and when.</p>
    </div>
    <a class="admin-button secondary" href="<?php echo BASE_URL; ?>/staff/inventory.php" style="height:44px; padding:0 20px; border-radius:999px; font-weight:800; display:inline-flex; align-items:center; gap:7px;">
        <i class="fa-solid fa-boxes-stacked"></i> Open inventory
    </a>
</section>

<section class="panel">
    <form method="GET" class="orders-filter-bar history-filter-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" placeholder="Search item, menu, change or staff name..." value="<?php echo htmlspecialchars($q); ?>" />
        </div>

        <div class="filter-actions">
            <div class="select-control" style="margin:0;">
                <select name="kind" onchange="this.form.submit()" aria-label="Change type">
                    <option value="all" <?php echo $kind === 'all' ? 'selected' : ''; ?>>All activity</option>
                    <option value="items" <?php echo $kind === 'items' ? 'selected' : ''; ?>>Inventory items</option>
                    <option value="menus" <?php echo $kind === 'menus' ? 'selected' : ''; ?>>Menus &amp; sizes</option>
                    <option value="stock" <?php echo $kind === 'stock' ? 'selected' : ''; ?>>Stock changes</option>
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
                <a href="inventory-history.php" class="admin-button secondary" style="height:42px; border-radius:8px; padding:0 16px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$days): ?>
        <div class="empty-state" style="text-align:center; padding:60px 20px;">
            <div style="width:64px; height:64px; margin:0 auto 16px; border-radius:50%; background:#faf7f4; display:flex; align-items:center; justify-content:center; font-size:26px; color:var(--admin-muted);">
                <i class="fa-solid <?php echo $q !== '' ? 'fa-magnifying-glass' : 'fa-box-open'; ?>"></i>
            </div>
            <h3 style="color:var(--admin-brown-dark); margin:0 0 6px; font-size:16px;">
                <?php echo $hasFilters ? 'No ' . htmlspecialchars($kindLabels[$kind]) . ' match those filters' : 'No inventory changes recorded yet'; ?>
            </h3>
            <p style="color:var(--admin-muted); font-size:13px; margin:0;">
                <?php echo $hasFilters
                    ? 'Try a different keyword, switch the change type, or reset the filters.'
                    : 'Adding, editing and deleting items, menus and stock will be logged here automatically.'; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="staff-activity-feed">
            <?php foreach ($days as $day): ?>
                <div class="staff-activity-day"><?php echo htmlspecialchars($day['label']); ?></div>
                <ul class="staff-activity-list">
                    <?php foreach ($day['items'] as $dayItem): $row = $dayItem['row']; ?>
                        <li class="staff-activity-item">
                            <span class="staff-activity-icon"><i class="fa-solid <?php echo $dayItem['icon']; ?>"></i></span>
                            <div class="staff-activity-copy">
                                <strong><?php echo htmlspecialchars($dayItem['title'] . ' — ' . $row['entity_name']); ?></strong>
                                <?php if (!empty($row['detail'])): ?>
                                    <small><?php echo htmlspecialchars((string) $row['detail']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($row['actor_name'])): ?>
                                    <small class="is-actor"><i class="fa-regular fa-user"></i> <?php echo htmlspecialchars((string) $row['actor_name']); ?></small>
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
