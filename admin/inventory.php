<?php
// =============================================================================
// admin/inventory.php  –  Inventory (view-only monitoring)
// Modernised stock monitor: stats, search, category/status filters, sorting,
// and a list/grid toggle. Product changes are handled by Staff.
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

function adminInventoryTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $statement->execute(['table_name' => $table]);
    return (bool) $statement->fetchColumn();
}

$pdo = getDatabaseConnection();
$photoDataColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'photo_data'")->fetchColumn();
if (!$photoDataColumn && adminInventoryTableExists($pdo, 'inventory_items')) {
    $pdo->exec("ALTER TABLE inventory_items ADD COLUMN photo_data MEDIUMBLOB NULL AFTER photo, ADD COLUMN photo_mime VARCHAR(50) NULL AFTER photo_data");
    $photoDataColumn = true;
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$search     = trim((string) ($_GET['search'] ?? ''));
$categoryId = (int) ($_GET['category'] ?? 0);
$stockState = trim((string) ($_GET['stock'] ?? ''));
$sort       = trim((string) ($_GET['sort'] ?? 'stock_asc'));
$view       = ($_GET['view'] ?? 'list') === 'grid' ? 'grid' : 'list';

if (mb_strlen($search) > 60) {
    $search = mb_substr($search, 0, 60);
}
if (!in_array($stockState, ['good', 'low', 'out'], true)) {
    $stockState = '';
}

// Whitelisted sort — never interpolate raw user input into ORDER BY.
$sortOptions = [
    'stock_asc'  => ['total_quantity ASC, i.name ASC', 'Lowest stock first'],
    'stock_desc' => ['total_quantity DESC, i.name ASC', 'Highest stock first'],
    'name'       => ['i.name ASC', 'Item name (A–Z)'],
    'name_desc'  => ['i.name DESC', 'Item name (Z–A)'],
    'variants'   => ['variant_count DESC, i.name ASC', 'Most variants'],
    'price_desc' => ['max_price DESC, i.name ASC', 'Highest price'],
    'price_asc'  => ['min_price ASC, i.name ASC', 'Lowest price'],
    'category'   => ['c.name ASC, i.name ASC', 'Category'],
];
$sortSql    = $sortOptions[$sort][0] ?? $sortOptions['stock_asc'][0];
$sortLabel  = $sortOptions[$sort][1] ?? $sortOptions['stock_asc'][1];

/* ── Query ───────────────────────────────────────────────────────────────── */
$inventoryItems = [];
$variantLookup  = [];
$categories     = [];
$statTotals     = ['items' => 0, 'low' => 0, 'out' => 0, 'units' => 0, 'value' => 0.0];

if (adminInventoryTableExists($pdo, 'inventory_items') && adminInventoryTableExists($pdo, 'inventory_item_variants')) {
    $categories = $pdo->query('SELECT id, name FROM menu_categories ORDER BY name ASC')->fetchAll();

    $where  = ' WHERE 1=1';
    $having = '';
    $params = [];

    if ($search !== '') {
        // Every LIKE needs its own bind key — native prepared statements reject
        // a repeated named placeholder.
        $where .= " AND (i.name LIKE :s_name OR i.description LIKE :s_desc OR c.name LIKE :s_cat
                     OR EXISTS (SELECT 1 FROM inventory_item_variants sv
                                WHERE sv.inventory_item_id = i.id AND sv.sku LIKE :s_sku))";
        $like = '%' . $search . '%';
        $params['s_name'] = $like;
        $params['s_desc'] = $like;
        $params['s_cat']  = $like;
        $params['s_sku']  = $like;
    }
    if ($categoryId > 0) {
        $where .= ' AND i.category_id = :category_id';
        $params['category_id'] = $categoryId;
    }
    if ($stockState !== '') {
        // total_quantity is an aggregate, so the stock level filters on a HAVING
        // clause — which must come AFTER the GROUP BY.
        $having = $stockState === 'out'
            ? ' HAVING total_quantity = 0'
            : ($stockState === 'low' ? ' HAVING total_quantity > 0 AND total_quantity <= 10' : ' HAVING total_quantity > 10');
    }

    $stmt = $pdo->prepare(
        "SELECT i.id, i.name, i.description, i.photo, c.name AS category_name,
                COUNT(v.id) AS variant_count,
                COALESCE(SUM(v.quantity), 0) AS total_quantity,
                MIN(v.price) AS min_price,
                MAX(v.price) AS max_price
         FROM inventory_items i
         JOIN menu_categories c ON c.id = i.category_id
         LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
         $where
         GROUP BY i.id, i.name, i.description, i.photo, c.name
         $having
         ORDER BY $sortSql"
    );
    $stmt->execute($params);
    $inventoryItems = $stmt->fetchAll();

    if ($inventoryItems) {
        $itemIds = array_column($inventoryItems, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $variantStatement = $pdo->prepare("SELECT * FROM inventory_item_variants WHERE inventory_item_id IN ($placeholders) ORDER BY FIELD(inventory_item_id, $placeholders), id ASC");
        $variantStatement->execute([...$itemIds, ...$itemIds]);
        foreach ($variantStatement->fetchAll() as $variant) {
            $variantLookup[$variant['inventory_item_id']][] = $variant;
        }
    }

    // Headline numbers always describe the whole catalogue, never the filtered
    // view — otherwise the summary would jump around while typing a search.
    $totalsRow = $pdo->query(
        "SELECT COUNT(DISTINCT i.id) AS items,
                COALESCE(SUM(v.quantity), 0) AS units,
                COALESCE(SUM(v.quantity * v.price), 0) AS value
         FROM inventory_items i
         LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id"
    )->fetch() ?: [];
    $statTotals['items'] = (int) ($totalsRow['items'] ?? 0);
    $statTotals['units'] = (int) ($totalsRow['units'] ?? 0);
    $statTotals['value'] = (float) ($totalsRow['value'] ?? 0);

    $stockRows = $pdo->query(
        'SELECT COALESCE(SUM(v.quantity), 0) AS qty
         FROM inventory_items i
         LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
         GROUP BY i.id'
    )->fetchAll();
    foreach ($stockRows as $stockRow) {
        $qty = (int) $stockRow['qty'];
        if ($qty === 0) {
            $statTotals['out']++;
        } elseif ($qty <= 10) {
            $statTotals['low']++;
        }
    }
}

/* ── Photos ──────────────────────────────────────────────────────────────── */
$photoRows = [];
if ($inventoryItems && $photoDataColumn) {
    $itemIds = array_column($inventoryItems, 'id');
    $photoPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
    $photoStatement = $pdo->prepare("SELECT id, photo_data, photo_mime FROM inventory_items WHERE id IN ($photoPlaceholders)");
    $photoStatement->execute($itemIds);
    foreach ($photoStatement->fetchAll() as $photoRow) {
        $photoRows[$photoRow['id']] = $photoRow;
    }
    foreach ($inventoryItems as &$inventoryItem) {
        $photoRow = $photoRows[$inventoryItem['id']] ?? [];
        if (!empty($photoRow['photo_data']) && !empty($photoRow['photo_mime'])) {
            $inventoryItem['photo'] = 'data:' . $photoRow['photo_mime'] . ';base64,' . base64_encode($photoRow['photo_data']);
        }
    }
    unset($inventoryItem);
}

$inventoryItems = array_map(static function (array $item): array {
    return [
        'id'             => (int) ($item['id'] ?? 0),
        'name'           => (string) ($item['name'] ?? 'Unnamed item'),
        'description'    => (string) ($item['description'] ?? ''),
        'photo'          => (string) ($item['photo'] ?? ''),
        'category_name'  => (string) ($item['category_name'] ?? 'Uncategorized'),
        'variant_count'  => (int) ($item['variant_count'] ?? 0),
        'total_quantity' => (int) ($item['total_quantity'] ?? 0),
        'min_price'      => (float) ($item['min_price'] ?? 0),
        'max_price'      => (float) ($item['max_price'] ?? 0),
    ];
}, $inventoryItems);

$hasFilters = $search !== '' || $categoryId > 0 || $stockState !== '';

/** Keeps the active filters when one control changes. */
function inventoryFilterUrl(array $overrides): string
{
    global $search, $categoryId, $stockState, $sort, $view;
    $query = array_filter([
        'search'   => $search,
        'category' => $categoryId ?: '',
        'stock'    => $stockState,
        'sort'     => $sort,
        'view'     => $view,
    ], static fn($value) => $value !== '' && $value !== null && $value !== 0);

    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query);
}

/** Stock state label + css modifier for one item. */
function inventoryStockState(int $quantity): array
{
    if ($quantity === 0) {
        return ['Out of Stock', 'out-of-stock'];
    }
    if ($quantity <= 10) {
        return ['Low Stock', 'low-stock'];
    }
    return ['Good', 'good'];
}

$pageTitle  = 'Inventory';
$activePage = 'inventory';
require __DIR__ . '/../includes/admin_header.php';
?><section class="page-intro">
    <h2>Inventory</h2>
    <p>Monitor products and stock levels across BreadBreak.</p>
</section>

<?php if ($inventoryItems || $hasFilters): ?>
<!-- ── Stats Row ── -->
<div class="voucher-stats-grid inventory-stats-row">
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-total"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div><strong><?php echo $statTotals['items']; ?></strong><span>Products</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-unused"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div><strong><?php echo $statTotals['low']; ?></strong><span>Low Stock</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-out"><i class="fa-solid fa-circle-xmark"></i></div>
        <div><strong><?php echo $statTotals['out']; ?></strong><span>Out of Stock</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-active"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><strong>₱<?php echo number_format($statTotals['value'], 0); ?></strong><span>Stock Value</span></div>
    </div>
</div>
<?php endif; ?>

<section class="panel admin-inventory-panel">
    <div class="panel-heading">
        <div>
            <h3>Stock Monitoring</h3>
            <p class="panel-subtitle">View-only inventory status. Product changes are handled by Staff.</p>
        </div>
        <div class="admin-inventory-heading-actions">
            <span class="monitoring-readonly"><i class="fa-solid fa-eye"></i> View only</span>
            <div class="inventory-view-toggle" role="group" aria-label="Inventory view">
                <button class="view-toggle-button<?php echo $view === 'list' ? ' is-active' : ''; ?>" type="button" data-admin-inventory-view="list" aria-label="List view" title="List view" aria-pressed="<?php echo $view === 'list' ? 'true' : 'false'; ?>"><i class="fa-solid fa-list"></i></button>
                <button class="view-toggle-button<?php echo $view === 'grid' ? ' is-active' : ''; ?>" type="button" data-admin-inventory-view="grid" aria-label="Grid view" title="Grid view" aria-pressed="<?php echo $view === 'grid' ? 'true' : 'false'; ?>"><i class="fa-solid fa-table-cells-large"></i></button>
            </div>
        </div>
    </div>

    <?php if ($inventoryItems || $hasFilters): ?>
    <!-- ── Search / Filter / Sort ── -->
    <form method="GET" class="reports-toolbar" data-inventory-focus="inventory-items">
        <div class="reports-toolbar-left">
            <label class="reports-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search product, SKU or category…" aria-label="Search inventory" />
            </label>
            <select name="category" class="reports-sort" onchange="this.form.submit()" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo $categoryId === (int) $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="stock" class="reports-sort" onchange="this.form.submit()" aria-label="Filter by stock">
                <option value="">All stock levels</option>
                <option value="good" <?php echo $stockState === 'good' ? 'selected' : ''; ?>>Good stock</option>
                <option value="low" <?php echo $stockState === 'low' ? 'selected' : ''; ?>>Low stock</option>
                <option value="out" <?php echo $stockState === 'out' ? 'selected' : ''; ?>>Out of stock</option>
            </select>
        </div>
        <div class="reports-toolbar-right">
            <?php if ($hasFilters): ?>
                <a href="<?php echo htmlspecialchars(inventoryFilterUrl(['search' => '', 'category' => '', 'stock' => ''])); ?>" class="reports-chip is-clear"><i class="fa-solid fa-xmark"></i> Clear</a>
            <?php endif; ?>
            <span class="reports-count"><?php echo count($inventoryItems); ?> items</span>
            <select name="sort" class="reports-sort" onchange="this.form.submit()" aria-label="Sort inventory">
                <?php foreach ($sortOptions as $key => $option): ?>
                    <option value="<?php echo $key; ?>" <?php echo $sort === $key ? 'selected' : ''; ?>>Sort: <?php echo $option[1]; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="view" value="<?php echo $view; ?>" />
        </div>
    </form>
    <?php endif; ?>

    <?php if ($inventoryItems): ?>
    <!-- ── List View ── -->
    <div class="admin-inventory-view admin-inventory-list" data-admin-inventory-content="list"<?php echo $view === 'grid' ? ' style="display:none"' : ''; ?>>
        <div class="table-wrap">
            <table class="admin-table admin-inventory-table">
                <thead>
                    <tr><th>Item</th><th>Category</th><th>Variants</th><th>Price Range</th><th>Total Stock</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($inventoryItems as $item):
                    list($status, $statusClass) = inventoryStockState((int) $item['total_quantity']);
                ?>
                    <tr>
                        <td>
                            <div class="inventory-cell">
                                <span class="inventory-cell-thumb">
                                    <?php if (!empty($item['photo'])): ?>
                                        <img src="<?php echo htmlspecialchars($item['photo']); ?>" alt="" />
                                    <?php else: ?>
                                        <i class="fa-solid fa-bread-slice"></i>
                                    <?php endif; ?>
                                </span>
                                <div class="inventory-cell-copy">
                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                    <?php if (!empty($item['description'])): ?>
                                        <small><?php echo htmlspecialchars(mb_strimwidth($item['description'], 0, 46, '…')); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-inventory-category"><?php echo htmlspecialchars($item['category_name']); ?></span></td>
                        <td><?php echo (int) $item['variant_count']; ?></td>
                        <td>₱<?php echo number_format((float) $item['min_price'], 2); ?><?php if ((float) $item['min_price'] !== (float) $item['max_price']): ?> – ₱<?php echo number_format((float) $item['max_price'], 2); ?><?php endif; ?></td>
                        <td>
                            <div class="inventory-stock-cell">
                                <span class="inventory-stock-number"><?php echo (int) $item['total_quantity']; ?> units</span>
                                <span class="admin-inventory-stock-meter" title="<?php echo (int) $item['total_quantity']; ?> units">
                                    <span class="admin-inventory-stock-fill is-<?php echo $statusClass; ?>" style="width:<?php echo min(100, (int) $item['total_quantity'] * 3); ?>%"></span>
                                </span>
                            </div>
                        </td>
                        <td><span class="inventory-monitor-badge <?php echo strtolower(str_replace(' ', '-', $status)); ?>"><?php echo $status; ?></span></td>
                        <td><button class="admin-button secondary admin-view-item" type="button" data-admin-item="<?php echo (int) $item['id']; ?>">View Item</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Grid View ── -->
    <div class="admin-inventory-view admin-inventory-grid" data-admin-inventory-content="grid"<?php echo $view === 'list' ? ' style="display:none"' : ''; ?>>
        <div class="admin-inventory-card-grid">
        <?php foreach ($inventoryItems as $item):
            list($status, $statusClass) = inventoryStockState((int) $item['total_quantity']);
        ?>
            <article class="admin-inventory-card">
                <div class="admin-inventory-card-image">
                    <?php if (!empty($item['photo'])): ?>
                        <img src="<?php echo htmlspecialchars($item['photo']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" />
                    <?php else: ?>
                        <span>No photo</span>
                    <?php endif; ?>
                </div>
                <div class="admin-inventory-card-content">
                    <span class="admin-inventory-category"><?php echo htmlspecialchars($item['category_name']); ?></span>
                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                    <div class="admin-inventory-stock-meter" title="<?php echo (int) $item['total_quantity']; ?> units">
                        <span class="admin-inventory-stock-fill is-<?php echo $statusClass; ?>" style="width:<?php echo min(100, (int) $item['total_quantity'] * 3); ?>%"></span>
                    </div>
                    <div class="admin-inventory-card-meta">
                        <span><?php echo (int) $item['total_quantity']; ?> units</span>
                        <span class="inventory-monitor-badge <?php echo strtolower(str_replace(' ', '-', $status)); ?>"><?php echo $status; ?></span>
                    </div>
                    <button class="admin-button secondary admin-view-item" type="button" data-admin-item="<?php echo (int) $item['id']; ?>">View Item</button>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
        <div class="vouchers-empty">
            <i class="fa-solid fa-box-open"></i>
            <p><?php echo $hasFilters
                  ? 'No products match the current filters.'
                  : 'No inventory data yet. Staff inventory items will appear here once added.'; ?></p>
            <?php if ($hasFilters): ?>
                <a href="<?php echo htmlspecialchars(inventoryFilterUrl(['search' => '', 'category' => '', 'stock' => ''])); ?>" class="reports-chip"><i class="fa-solid fa-filter-circle-xmark"></i> Clear filters</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.querySelector('.reports-search input[type="search"]');
    if (!searchInput) return;
    let timer = null;
    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { searchInput.closest('form').submit(); }, 700);
    });
});
</script>

<div class="admin-modal admin-inventory-modal" id="admin-inventory-modal" role="dialog" aria-modal="true" aria-labelledby="admin-inventory-modal-title">
    <div class="modal-heading">
        <div><span class="modal-kicker">Inventory Details</span><h2 id="admin-inventory-modal-title">View Item</h2></div>
        <button class="modal-close" type="button" data-admin-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="admin-inventory-overview">
        <div class="admin-inventory-modal-photo" data-admin-detail="photo">No photo available</div>
        <dl class="item-detail-list">
            <div><dt>Item</dt><dd data-admin-detail="name"></dd></div>
            <div><dt>Category</dt><dd data-admin-detail="category"></dd></div>
            <div><dt>Description</dt><dd data-admin-detail="description"></dd></div>
        </dl>
    </div>
    <h3 class="variant-section-title">Service Size Variants</h3>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Service Size</th><th>SKU</th><th>Price</th><th>Quantity</th><th>Stock Status</th><th>Availability</th></tr></thead>
            <tbody data-admin-detail="variants"></tbody>
        </table>
    </div>
    <div class="view-summary">
        <strong>Total Stock: <span data-admin-detail="total-stock"></span></strong>
        <strong>Price Range: <span data-admin-detail="price-range"></span></strong>
    </div>
    <div class="modal-actions"><button class="admin-button secondary" type="button" data-admin-modal-close>Close</button></div>
</div>
<div class="modal-backdrop" data-admin-modal-backdrop></div>

<script>
const adminInventoryItems = <?php echo json_encode(array_map(static function (array $item) use ($variantLookup): array { $item['variants'] = $variantLookup[$item['id']] ?? []; return $item; }, $inventoryItems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('admin-inventory-modal');
    const backdrop = document.querySelector('[data-admin-modal-backdrop]');
    function closeModal() { modal.classList.remove('is-open'); backdrop.classList.remove('is-open'); }
    document.querySelectorAll('[data-admin-modal-close]').forEach(function (button) { button.addEventListener('click', closeModal); });

    // View toggle — swaps in place so filters are not lost, and remembers the
    // choice for the next page load.
    document.querySelectorAll('[data-admin-inventory-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            const view = button.dataset.adminInventoryView;
            document.querySelectorAll('[data-admin-inventory-content]').forEach(function (content) {
                content.style.display = content.dataset.adminInventoryContent === view ? 'block' : 'none';
            });
            document.querySelectorAll('[data-admin-inventory-view]').forEach(function (toggle) {
                const active = toggle.dataset.adminInventoryView === view;
                toggle.classList.toggle('is-active', active);
                toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            const hidden = document.querySelector('input[name="view"]');
            if (hidden) hidden.value = view;
            // Keep the choice in the URL so a refresh (or a shared link) stays on
            // the same view without losing the active filters.
            const url = new URL(window.location.href);
            url.searchParams.set('view', view);
            window.history.replaceState({}, '', url);
        });
    });

    document.querySelectorAll('[data-admin-item]').forEach(function (button) {
        button.addEventListener('click', function () {
            const item = adminInventoryItems.find(function (entry) { return Number(entry.id) === Number(button.dataset.adminItem); });
            if (!item) return;
            document.querySelector('[data-admin-detail="name"]').textContent = item.name;
            document.querySelector('[data-admin-detail="category"]').textContent = item.category_name;
            document.querySelector('[data-admin-detail="description"]').textContent = item.description;
            const photo = document.querySelector('[data-admin-detail="photo"]');
            photo.innerHTML = item.photo ? '<img src="' + item.photo + '" alt="' + item.name.replace(/"/g, '&quot;') + '">' : 'No photo available';
            let totalStock = 0;
            const prices = [];
            document.querySelector('[data-admin-detail="variants"]').innerHTML = item.variants.map(function (variant) {
                const quantity = Number(variant.quantity);
                totalStock += quantity;
                prices.push(Number(variant.price));
                const status = quantity > 10 ? 'In Stock' : quantity > 0 ? 'Low Stock' : 'Out of Stock';
                return '<tr><td>' + variant.service_size + '</td><td>' + variant.sku + '</td><td>₱' + Number(variant.price).toFixed(2) + '</td><td>' + quantity + '</td><td><span class="stock-badge ' + (quantity > 10 ? 'stock-in' : quantity > 0 ? 'stock-low' : 'stock-out') + '">' + status + '</span></td><td>' + (variant.availability === 'available' ? 'Available' : 'Unavailable') + '</td></tr>';
            }).join('');
            document.querySelector('[data-admin-detail="total-stock"]').textContent = totalStock;
            document.querySelector('[data-admin-detail="price-range"]').textContent = prices.length ? '₱' + Math.min.apply(null, prices).toFixed(2) + (prices.length > 1 ? ' – ₱' + Math.max.apply(null, prices).toFixed(2) : '') : '₱0.00';
            modal.classList.add('is-open');
            backdrop.classList.add('is-open');
        });
    });

    // Live search — keeps the page on the toolbar while typing.
    const searchInput = document.querySelector('.reports-search input[type="search"]');
    if (searchInput) {
        let timer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { searchInput.closest('form').submit(); }, 700);
        });
    }
});
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>