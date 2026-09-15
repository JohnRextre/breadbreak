<?php
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
if (!$photoDataColumn && adminInventoryTableExists($pdo, 'inventory_items')) { $pdo->exec("ALTER TABLE inventory_items ADD COLUMN photo_data MEDIUMBLOB NULL AFTER photo, ADD COLUMN photo_mime VARCHAR(50) NULL AFTER photo_data"); $photoDataColumn = true; }
$inventoryItems = [];
$variantLookup = [];
if (adminInventoryTableExists($pdo, 'inventory_items') && adminInventoryTableExists($pdo, 'inventory_item_variants')) {
    $inventoryItems = $pdo->query('SELECT i.id, i.name, i.description, i.photo, c.name AS category_name, COUNT(v.id) AS variant_count, COALESCE(SUM(v.quantity), 0) AS total_quantity, MIN(v.price) AS min_price, MAX(v.price) AS max_price FROM inventory_items i JOIN menu_categories c ON c.id = i.category_id LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id GROUP BY i.id, i.name, i.description, i.photo, c.name ORDER BY total_quantity ASC, i.name ASC')->fetchAll();
    if ($inventoryItems) {
        $itemIds = array_column($inventoryItems, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $variantStatement = $pdo->prepare("SELECT * FROM inventory_item_variants WHERE inventory_item_id IN ($placeholders) ORDER BY FIELD(inventory_item_id, $placeholders), id ASC");
        $variantStatement->execute([...$itemIds, ...$itemIds]);
        foreach ($variantStatement->fetchAll() as $variant) $variantLookup[$variant['inventory_item_id']][] = $variant;
    }
}
$photoRows = [];
if ($inventoryItems && $photoDataColumn) {
    $itemIds = array_column($inventoryItems, 'id');
    $photoPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
    $photoStatement = $pdo->prepare("SELECT id, photo_data, photo_mime FROM inventory_items WHERE id IN ($photoPlaceholders)");
    $photoStatement->execute($itemIds);
    foreach ($photoStatement->fetchAll() as $photoRow) $photoRows[$photoRow['id']] = $photoRow;
    foreach ($inventoryItems as &$inventoryItem) { $photoRow = $photoRows[$inventoryItem['id']] ?? []; if (!empty($photoRow['photo_data']) && !empty($photoRow['photo_mime'])) $inventoryItem['photo'] = 'data:' . $photoRow['photo_mime'] . ';base64,' . base64_encode($photoRow['photo_data']); } unset($inventoryItem);
}
$inventoryItems = array_map(static function (array $item): array { return ['id' => (int) ($item['id'] ?? 0), 'name' => (string) ($item['name'] ?? 'Unnamed item'), 'description' => (string) ($item['description'] ?? ''), 'photo' => (string) ($item['photo'] ?? ''), 'category_name' => (string) ($item['category_name'] ?? 'Uncategorized'), 'variant_count' => (int) ($item['variant_count'] ?? 0), 'total_quantity' => (int) ($item['total_quantity'] ?? 0), 'min_price' => (float) ($item['min_price'] ?? 0), 'max_price' => (float) ($item['max_price'] ?? 0)]; }, $inventoryItems);
$pageTitle = 'Inventory';
$activePage = 'inventory';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>Inventory</h2><p>Monitor products and stock levels across BreadBreak.</p></section>
<section class="panel admin-inventory-panel"><div class="panel-heading"><div><h3>Stock Monitoring</h3><p class="panel-subtitle">View-only inventory status. Product changes are handled by Staff.</p></div><div class="admin-inventory-heading-actions"><span class="monitoring-readonly"><i class="fa-solid fa-eye"></i> View only</span><div class="inventory-view-toggle" role="group" aria-label="Inventory view"><button class="view-toggle-button is-active" type="button" data-admin-inventory-view="list" aria-label="List view" title="List view" aria-pressed="true"><i class="fa-solid fa-list"></i></button><button class="view-toggle-button" type="button" data-admin-inventory-view="grid" aria-label="Grid view" title="Grid view" aria-pressed="false"><i class="fa-solid fa-table-cells-large"></i></button></div></div></div>
<?php if ($inventoryItems): ?>
<div class="admin-inventory-view admin-inventory-list" data-admin-inventory-content="list"><div class="table-wrap"><table class="admin-table admin-inventory-table"><thead><tr><th>Item</th><th>Category</th><th>Variants</th><th>Price Range</th><th>Total Stock</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($inventoryItems as $item): $status = (int) $item['total_quantity'] === 0 ? 'Out of Stock' : ((int) $item['total_quantity'] <= 10 ? 'Low Stock' : 'Good'); ?><tr><td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td><td><?php echo htmlspecialchars($item['category_name']); ?></td><td><?php echo (int) $item['variant_count']; ?></td><td>&#8369;<?php echo number_format((float) $item['min_price'], 2); ?><?php if ((float) $item['min_price'] !== (float) $item['max_price']): ?> &ndash; &#8369;<?php echo number_format((float) $item['max_price'], 2); ?><?php endif; ?></td><td><?php echo (int) $item['total_quantity']; ?> units</td><td><span class="inventory-monitor-badge <?php echo strtolower(str_replace(' ', '-', $status)); ?>"><?php echo $status; ?></span></td><td><button class="admin-button secondary admin-view-item" type="button" data-admin-item="<?php echo (int) $item['id']; ?>">View Item</button></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="admin-inventory-view admin-inventory-grid" data-admin-inventory-content="grid"><div class="admin-inventory-card-grid"><?php foreach ($inventoryItems as $item): $status = (int) $item['total_quantity'] === 0 ? 'Out of Stock' : ((int) $item['total_quantity'] <= 10 ? 'Low Stock' : 'Good'); ?><article class="admin-inventory-card"><div class="admin-inventory-card-image"><?php if (!empty($item['photo'])): ?><img src="<?php echo htmlspecialchars($item['photo']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" /><?php else: ?><span>No photo</span><?php endif; ?></div><div class="admin-inventory-card-content"><span class="admin-inventory-category"><?php echo htmlspecialchars($item['category_name']); ?></span><h4><?php echo htmlspecialchars($item['name']); ?></h4><div class="admin-inventory-card-meta"><span><?php echo (int) $item['total_quantity']; ?> units</span><span class="inventory-monitor-badge <?php echo strtolower(str_replace(' ', '-', $status)); ?>"><?php echo $status; ?></span></div><button class="admin-button secondary admin-view-item" type="button" data-admin-item="<?php echo (int) $item['id']; ?>">View Item</button></div></article><?php endforeach; ?></div></div>
<?php else: ?><div class="placeholder-panel"><div><strong>No inventory data yet.</strong><p>Staff inventory items will appear here once added.</p></div></div><?php endif; ?></section>
<div class="admin-modal admin-inventory-modal" id="admin-inventory-modal" role="dialog" aria-modal="true" aria-labelledby="admin-inventory-modal-title"><div class="modal-heading"><div><span class="modal-kicker">Inventory Details</span><h2 id="admin-inventory-modal-title">View Item</h2></div><button class="modal-close" type="button" data-admin-modal-close aria-label="Close">&times;</button></div><div class="admin-inventory-overview"><div class="admin-inventory-modal-photo" data-admin-detail="photo">No photo available</div><dl class="item-detail-list"><div><dt>Item</dt><dd data-admin-detail="name"></dd></div><div><dt>Category</dt><dd data-admin-detail="category"></dd></div><div><dt>Description</dt><dd data-admin-detail="description"></dd></div></dl></div><h3 class="variant-section-title">Service Size Variants</h3><div class="table-wrap"><table class="admin-table"><thead><tr><th>Service Size</th><th>SKU</th><th>Price</th><th>Quantity</th><th>Stock Status</th><th>Availability</th></tr></thead><tbody data-admin-detail="variants"></tbody></table></div><div class="view-summary"><strong>Total Stock: <span data-admin-detail="total-stock"></span></strong><strong>Price Range: <span data-admin-detail="price-range"></span></strong></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-admin-modal-close>Close</button></div></div><div class="modal-backdrop" data-admin-modal-backdrop></div>
<script>
const adminInventoryItems = <?php echo json_encode(array_map(static function (array $item) use ($variantLookup): array { $item['variants'] = $variantLookup[$item['id']] ?? []; return $item; }, $inventoryItems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('admin-inventory-modal');
    const backdrop = document.querySelector('[data-admin-modal-backdrop]');
    function closeModal() { modal.classList.remove('is-open'); backdrop.classList.remove('is-open'); }
    document.querySelectorAll('[data-admin-modal-close]').forEach(function (button) { button.addEventListener('click', closeModal); });
    document.querySelectorAll('[data-admin-inventory-view]').forEach(function (button) { button.addEventListener('click', function () { const view = button.dataset.adminInventoryView; document.querySelectorAll('[data-admin-inventory-content]').forEach(function (content) { content.style.display = content.dataset.adminInventoryContent === view ? 'block' : 'none'; }); document.querySelectorAll('[data-admin-inventory-view]').forEach(function (toggle) { const active = toggle.dataset.adminInventoryView === view; toggle.classList.toggle('is-active', active); toggle.setAttribute('aria-pressed', active ? 'true' : 'false'); }); }); });
    document.querySelectorAll('[data-admin-inventory-content]').forEach(function (content) { content.style.display = content.dataset.adminInventoryContent === 'list' ? 'block' : 'none'; });
    document.querySelectorAll('[data-admin-item]').forEach(function (button) { button.addEventListener('click', function () { const item = adminInventoryItems.find(function (entry) { return Number(entry.id) === Number(button.dataset.adminItem); }); if (!item) return; document.querySelector('[data-admin-detail="name"]').textContent = item.name; document.querySelector('[data-admin-detail="category"]').textContent = item.category_name; document.querySelector('[data-admin-detail="description"]').textContent = item.description; const photo = document.querySelector('[data-admin-detail="photo"]'); photo.innerHTML = item.photo ? '<img src="' + item.photo + '" alt="' + item.name.replace(/"/g, '&quot;') + '">' : 'No photo available'; let totalStock = 0; const prices = []; document.querySelector('[data-admin-detail="variants"]').innerHTML = item.variants.map(function (variant) { const quantity = Number(variant.quantity); totalStock += quantity; prices.push(Number(variant.price)); const status = quantity > 10 ? 'In Stock' : quantity > 0 ? 'Low Stock' : 'Out of Stock'; return '<tr><td>' + variant.service_size + '</td><td>' + variant.sku + '</td><td>₱' + Number(variant.price).toFixed(2) + '</td><td>' + quantity + '</td><td><span class="stock-badge ' + (quantity > 10 ? 'stock-in' : quantity > 0 ? 'stock-low' : 'stock-out') + '">' + status + '</span></td><td>' + (variant.availability === 'available' ? 'Available' : 'Unavailable') + '</td></tr>'; }).join(''); document.querySelector('[data-admin-detail="total-stock"]').textContent = totalStock; document.querySelector('[data-admin-detail="price-range"]').textContent = prices.length ? '₱' + Math.min.apply(null, prices).toFixed(2) + (prices.length > 1 ? ' – ₱' + Math.max.apply(null, prices).toFixed(2) : '') : '₱0.00'; modal.classList.add('is-open'); backdrop.classList.add('is-open'); }); });
});
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
