<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();

// Auto-migrate tables
$pdo->exec("CREATE TABLE IF NOT EXISTS menu_categories (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(120) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(150) NOT NULL, category_id INT NOT NULL, description TEXT NOT NULL, photo VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_inventory_category FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON UPDATE CASCADE ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$photoDataColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'photo_data'")->fetchColumn();
if (!$photoDataColumn) $pdo->exec("ALTER TABLE inventory_items ADD COLUMN photo_data MEDIUMBLOB NULL AFTER photo, ADD COLUMN photo_mime VARCHAR(50) NULL AFTER photo_data");

// Menu categories can carry their own cover photo (shown on the Shop landing tiles).
$categoryPhotoColumns = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'menu_categories' AND column_name IN ('photo_data','photo_mime')")->fetchColumn();
if ((int) $categoryPhotoColumns < 2) $pdo->exec("ALTER TABLE menu_categories ADD COLUMN photo_data MEDIUMBLOB NULL, ADD COLUMN photo_mime VARCHAR(50) NULL");

$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_item_variants (id INT PRIMARY KEY AUTO_INCREMENT, inventory_item_id INT NOT NULL, service_size VARCHAR(100) NOT NULL, sku VARCHAR(80) NOT NULL UNIQUE, price DECIMAL(10,2) NOT NULL, quantity INT NOT NULL DEFAULT 0, availability ENUM('available','unavailable') NOT NULL DEFAULT 'available', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_variant_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE CASCADE, UNIQUE KEY unique_item_service_size (inventory_item_id, service_size)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sizeColType = $pdo->query("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_item_variants' AND column_name = 'service_size'")->fetchColumn();
if ($sizeColType === 'enum') {
    $pdo->exec("ALTER TABLE inventory_item_variants MODIFY COLUMN service_size VARCHAR(100) NOT NULL");
}

$legacyColumns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name IN ('price', 'quantity', 'availability')")->fetchAll(PDO::FETCH_COLUMN);
if ($legacyColumns && (int) $pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE inventory_items DROP COLUMN price, DROP COLUMN quantity, DROP COLUMN availability');
}

$pdo->exec("CREATE TABLE IF NOT EXISTS category_service_sizes (id INT PRIMARY KEY AUTO_INCREMENT, category_id INT NOT NULL, size_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_category_size FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON UPDATE CASCADE ON DELETE CASCADE, UNIQUE KEY unique_category_size (category_id, size_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$defaultMenus = ['Bite-sized Breads', 'Big Breads', 'Palm-sized Cookies', 'Cookies', 'Pastries', 'Crinkles', 'Decadent Cakes', 'Round Cakes'];
$seed = $pdo->prepare('INSERT IGNORE INTO menu_categories (name) VALUES (:name)');
foreach ($defaultMenus as $menu) $seed->execute(['name' => $menu]);

// Seed default category sizes
$defaultCategorySizes = [
    'Bite-sized Breads' => ['Solo', 'Partner', 'Family'],
    'Big Breads' => ['Solo', 'Partner', 'Family'],
    'Palm-sized Cookies' => ['1pc', '10pcs tub'],
    'Cookies' => ['1pc', '10pcs tub'],
    'Pastries' => ['1pc', '10pcs tub'],
    'Crinkles' => ['1pc', '10pcs tub'],
    'Decadent Cakes' => ['1pc', '10pcs tub'],
    'Round Cakes' => ['6-inch round', '8-inch round'],
];
$categorySizeCount = (int) $pdo->query('SELECT COUNT(*) FROM category_service_sizes')->fetchColumn();
if ($categorySizeCount === 0) {
    $insertSize = $pdo->prepare('INSERT IGNORE INTO category_service_sizes (category_id, size_name) VALUES (:category_id, :size_name)');
    $catQuery = $pdo->query('SELECT id, name FROM menu_categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($catQuery as $cId => $cName) {
        $sizesToInsert = $defaultCategorySizes[$cName] ?? ['1pc', '10pcs tub'];
        foreach ($sizesToInsert as $sName) {
            $insertSize->execute(['category_id' => $cId, 'size_name' => $sName]);
        }
    }
}

// Fetch category service sizes
$allCategorySizes = [];
$catSizeStmt = $pdo->query('SELECT category_id, size_name FROM category_service_sizes ORDER BY id ASC');
foreach ($catSizeStmt->fetchAll() as $row) {
    $allCategorySizes[(int) $row['category_id']][] = $row['size_name'];
}

$categories = $pdo->query('SELECT id, name, (photo_data IS NOT NULL) AS has_photo FROM menu_categories ORDER BY id ASC')->fetchAll();
$categoryNameMap = [];
foreach ($categories as $cat) {
    $categoryNameMap[(int) $cat['id']] = $cat['name'];
    if (empty($allCategorySizes[(int) $cat['id']])) {
        $cName = $cat['name'];
        if (stripos($cName, 'round cake') !== false || stripos($cName, 'cake') !== false) {
            $allCategorySizes[(int) $cat['id']] = ['6-inch round', '8-inch round'];
        } elseif (stripos($cName, 'bread') !== false) {
            $allCategorySizes[(int) $cat['id']] = ['Solo', 'Partner', 'Family'];
        } else {
            $allCategorySizes[(int) $cat['id']] = ['1pc', '10pcs tub'];
        }
    }
}

$pageTitle = 'Inventory';
$activePage = 'inventory';
$errors = [];
$successMessage = $_SESSION['staff_inventory_success'] ?? '';
unset($_SESSION['staff_inventory_success']);
$modal = '';
$formData = [];
$selectedItem = null;
$selectedVariants = [];
$categoryId = (int) ($_POST['category_id'] ?? 0);

function inventoryStockStatus(int $quantity): array { return $quantity > 10 ? ['In Stock', 'stock-in'] : ($quantity > 0 ? ['Low Stock', 'stock-low'] : ['Out of Stock', 'stock-out']); }
function inventoryRedirect(string $message): void { $_SESSION['staff_inventory_success'] = $message; header('Location: ' . BASE_URL . '/staff/inventory.php'); exit; }
function saveInventoryPhoto(int $itemId): ?array {
    return readUploadedImage('photo');
}
function readUploadedImage(string $field): ?array {
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$field]['tmp_name'])) throw new RuntimeException('Invalid photo upload.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Only JPG, PNG, and WEBP photos are allowed.');
    $contents = file_get_contents($_FILES[$field]['tmp_name']);
    if ($contents === false) throw new RuntimeException('Unable to read the uploaded photo.');
    return ['data' => $contents, 'mime' => $mime];
}
function variantInputRows(array $input): array {
    $rows = [];
    foreach (($input['size'] ?? []) as $index => $size) {
        $rows[] = ['service_size' => trim((string) $size), 'sku' => trim((string) (($input['sku'] ?? [])[$index] ?? '')), 'price' => trim((string) (($input['price'] ?? [])[$index] ?? '')), 'quantity' => (int) (($input['quantity'] ?? [])[$index] ?? 0), 'availability' => (($input['availability'] ?? [])[$index] ?? '') === 'unavailable' ? 'unavailable' : 'available'];
    }
    return $rows;
}
function validateVariants(array $variants, array $allowedSizes, PDO $pdo, int $itemId = 0): array {
    $errors = [];
    if (!$variants) return ['variants' => 'At least one Service Size variant is required.'];
    $seenSizes = [];
    $seenSkus = [];
    foreach ($variants as $index => $variant) {
        $line = $index + 1;
        if ($variant['service_size'] === '') {
            $errors["variant_{$index}"] = "Variant {$line}: select a valid Service Size.";
        } elseif ($allowedSizes && !in_array($variant['service_size'], $allowedSizes, true)) {
            $errors["variant_{$index}"] = "Variant {$line}: '{$variant['service_size']}' is not a valid Service Size for this category.";
        } elseif (in_array($variant['service_size'], $seenSizes, true)) {
            $errors["variant_{$index}"] = 'This Service Size has already been added.';
        }
        $seenSizes[] = $variant['service_size'];
        if ($variant['sku'] === '') $errors["variant_{$index}"] = "Variant {$line}: SKU is required.";
        elseif (in_array($variant['sku'], $seenSkus, true)) $errors["variant_{$index}"] = 'This SKU is already in use. Please enter a unique SKU.';
        $seenSkus[] = $variant['sku'];
        if ($variant['price'] === '' || !is_numeric($variant['price']) || (float) $variant['price'] < 0) $errors["variant_{$index}"] = "Variant {$line}: enter a valid non-negative price.";
        if ($variant['quantity'] < 0) $errors["variant_{$index}"] = "Variant {$line}: quantity cannot be negative.";
    }
    if ($seenSkus) {
        $placeholders = implode(',', array_fill(0, count($seenSkus), '?'));
        $statement = $pdo->prepare("SELECT sku FROM inventory_item_variants WHERE sku IN ($placeholders) AND inventory_item_id <> ?");
        $statement->execute([...$seenSkus, $itemId]);
        if ($statement->fetch()) $errors['sku'] = 'This SKU is already in use. Please enter a unique SKU.';
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($action === 'create_menu' || $action === 'edit_menu') {
        $name = trim($_POST['menu_name'] ?? ''); $categoryId = (int) ($_POST['category_id'] ?? 0); $modal = $action === 'create_menu' ? 'add-menu-modal' : 'edit-menu-modal';
        $categoryPhoto = null;
        if ($name === '') $errors['menu_name'] = 'Menu name is required.';
        try {
            $categoryPhoto = readUploadedImage('menu_photo');
        } catch (Throwable $photoException) {
            $errors['menu_photo'] = $photoException->getMessage();
        }
        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM menu_categories WHERE name = :name AND id <> :id LIMIT 1');
            $check->execute(['name' => $name, 'id' => $categoryId]);
            if ($check->fetch()) $errors['menu_name'] = 'This menu category already exists.';
            else {
                $statement = $action === 'create_menu' ? $pdo->prepare('INSERT INTO menu_categories (name) VALUES (:name)') : $pdo->prepare('UPDATE menu_categories SET name = :name WHERE id = :id');
                $statement->execute($action === 'create_menu' ? ['name' => $name] : ['name' => $name, 'id' => $categoryId]);
                $newCatId = $action === 'create_menu' ? (int) $pdo->lastInsertId() : $categoryId;
                if ($categoryPhoto && $newCatId) {
                    $pdo->prepare('UPDATE menu_categories SET photo_data = :photo_data, photo_mime = :photo_mime WHERE id = :id')
                        ->execute(['photo_data' => $categoryPhoto['data'], 'photo_mime' => $categoryPhoto['mime'], 'id' => $newCatId]);
                }
                if ($action === 'create_menu' && $newCatId) {
                    // Seed default sizes for newly created category
                    $cName = $name;
                    $defaultNewSizes = ['1pc', '10pcs tub'];
                    if (stripos($cName, 'round cake') !== false || stripos($cName, 'cake') !== false) $defaultNewSizes = ['6-inch round', '8-inch round'];
                    elseif (stripos($cName, 'bread') !== false) $defaultNewSizes = ['Solo', 'Partner', 'Family'];
                    $insertSize = $pdo->prepare('INSERT IGNORE INTO category_service_sizes (category_id, size_name) VALUES (:category_id, :size_name)');
                    foreach ($defaultNewSizes as $sName) $insertSize->execute(['category_id' => $newCatId, 'size_name' => $sName]);
                }
                inventoryRedirect($action === 'create_menu' ? 'Menu category added successfully.' : 'Menu category updated successfully.');
            }
        }
    } elseif ($action === 'create_category_size') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $sizeName = trim($_POST['size_name'] ?? '');
        $modal = 'add-size-modal';
        if (!$categoryId) {
            $errors['size_name'] = 'Please select a menu category.';
        } elseif ($sizeName === '') {
            $errors['size_name'] = 'Service size / variant name is required.';
        } else {
            $catCheck = $pdo->prepare('SELECT id FROM menu_categories WHERE id = :id LIMIT 1');
            $catCheck->execute(['id' => $categoryId]);
            if (!$catCheck->fetch()) {
                $errors['size_name'] = 'Selected category does not exist.';
            } else {
                $check = $pdo->prepare('SELECT id FROM category_service_sizes WHERE category_id = :category_id AND LOWER(TRIM(size_name)) = LOWER(TRIM(:size_name)) LIMIT 1');
                $check->execute(['category_id' => $categoryId, 'size_name' => $sizeName]);
                if ($check->fetch()) {
                    $errors['size_name'] = 'This service size variant already exists in the selected category.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO category_service_sizes (category_id, size_name) VALUES (:category_id, :size_name)');
                    $stmt->execute(['category_id' => $categoryId, 'size_name' => $sizeName]);
                    inventoryRedirect('Service size variant added to menu category successfully.');
                }
            }
        }
    } elseif ($action === 'delete_menu') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE category_id = :id'); $count->execute(['id' => $categoryId]); if ((int) $count->fetchColumn() > 0) { $errors['menu_delete'] = 'This menu contains inventory items. Please move or remove the items from this category before deleting the menu.'; $modal = 'delete-menu-modal'; } else { $delete = $pdo->prepare('DELETE FROM menu_categories WHERE id = :id'); $delete->execute(['id' => $categoryId]); inventoryRedirect('Menu category deleted successfully.'); }
    } elseif ($action === 'create_item' || $action === 'edit_item') {
        $formData = ['name' => trim($_POST['name'] ?? ''), 'category_id' => (int) ($_POST['category_id'] ?? 0), 'description' => trim($_POST['description'] ?? ''), 'photo' => '', 'variants' => variantInputRows($_POST)]; $categoryId = $formData['category_id']; $modal = $action === 'create_item' ? 'add-item-modal' : 'edit-item-modal'; $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($formData['name'] === '') $errors['item_name'] = 'Item name is required.';
        if (!$formData['category_id']) $errors['item_category'] = 'Please select a category.';
        if ($formData['description'] === '') $errors['item_description'] = 'Description is required.';
        $categoryCheck = $pdo->prepare('SELECT id FROM menu_categories WHERE id = :id LIMIT 1'); $categoryCheck->execute(['id' => $formData['category_id']]);
        if ($formData['category_id'] && !$categoryCheck->fetch()) $errors['item_category'] = 'Please select a valid category.';
        $existingItem = null;
        if ($action === 'edit_item') { $itemCheck = $pdo->prepare('SELECT id, name, category_id, description, photo FROM inventory_items WHERE id = :id LIMIT 1'); $itemCheck->execute(['id' => $itemId]); $existingItem = $itemCheck->fetch(); if (!$existingItem) $errors['item'] = 'Inventory item was not found.'; }
        $allowedCategorySizes = $allCategorySizes[$formData['category_id']] ?? [];
        $errors = array_merge($errors, validateVariants($formData['variants'], $allowedCategorySizes, $pdo, $action === 'edit_item' ? $itemId : 0));
        if (!$errors && $action === 'edit_item' && $existingItem) {
            $existingVariantsStatement = $pdo->prepare('SELECT service_size, sku, price, quantity, availability FROM inventory_item_variants WHERE inventory_item_id = :id ORDER BY service_size');
            $existingVariantsStatement->execute(['id' => $itemId]);
            $existingVariants = $existingVariantsStatement->fetchAll();
            $normalizeVariants = static function (array $variants): string { foreach ($variants as &$variant) { $variant['quantity'] = (int) $variant['quantity']; $variant['price'] = number_format((float) $variant['price'], 2, '.', ''); } unset($variant); usort($variants, static fn (array $left, array $right): int => strcmp($left['service_size'], $right['service_size'])); return json_encode($variants); };
            $itemChanged = $existingItem['name'] !== $formData['name'] || (int) $existingItem['category_id'] !== $formData['category_id'] || $existingItem['description'] !== $formData['description'];
            $photoChanged = !empty($_FILES['photo']['name']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if (!$itemChanged && !$photoChanged && $normalizeVariants($existingVariants) === $normalizeVariants($formData['variants'])) inventoryRedirect('No changes were made.');
        }
        if (!$errors) {
            try { $pdo->beginTransaction();
                if ($action === 'create_item') { $statement = $pdo->prepare('INSERT INTO inventory_items (name, category_id, description) VALUES (:name, :category_id, :description)'); $statement->execute(['name' => $formData['name'], 'category_id' => $formData['category_id'], 'description' => $formData['description']]); $itemId = (int) $pdo->lastInsertId(); }
                else { $statement = $pdo->prepare('UPDATE inventory_items SET name = :name, category_id = :category_id, description = :description WHERE id = :id'); $statement->execute(['name' => $formData['name'], 'category_id' => $formData['category_id'], 'description' => $formData['description'], 'id' => $itemId]); $pdo->prepare('DELETE FROM inventory_item_variants WHERE inventory_item_id = :id')->execute(['id' => $itemId]); }
                $photo = saveInventoryPhoto($itemId); if ($photo) $pdo->prepare('UPDATE inventory_items SET photo = NULL, photo_data = :photo_data, photo_mime = :photo_mime WHERE id = :id')->execute(['photo_data' => $photo['data'], 'photo_mime' => $photo['mime'], 'id' => $itemId]);
                $variantStatement = $pdo->prepare('INSERT INTO inventory_item_variants (inventory_item_id, service_size, sku, price, quantity, availability) VALUES (:item_id, :service_size, :sku, :price, :quantity, :availability)'); foreach ($formData['variants'] as $variant) $variantStatement->execute(['item_id' => $itemId, 'service_size' => $variant['service_size'], 'sku' => $variant['sku'], 'price' => $variant['price'], 'quantity' => $variant['quantity'], 'availability' => $variant['availability']]); $pdo->commit(); inventoryRedirect($action === 'create_item' ? 'Inventory item added successfully.' : 'Inventory item updated successfully.');
            } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors['item'] = $exception->getMessage() ?: 'Unable to save the inventory item.'; }
        }
    } elseif ($action === 'delete_quantity') {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $deleteQuantity = filter_var($_POST['delete_quantity'] ?? null, FILTER_VALIDATE_INT);
        $quantityStatement = $pdo->prepare('SELECT quantity FROM inventory_item_variants WHERE id = :variant_id AND inventory_item_id = :item_id LIMIT 1');
        $quantityStatement->execute(['variant_id' => $variantId, 'item_id' => $itemId]);
        $variant = $quantityStatement->fetch();
        if (!$variant || $deleteQuantity === false || $deleteQuantity < 1 || $deleteQuantity > (int) $variant['quantity']) {
            $errors['delete_quantity'] = 'Enter a whole number greater than 0 and no greater than the current quantity.';
            $modal = 'delete-modal';
        } else {
            $update = $pdo->prepare('UPDATE inventory_item_variants SET quantity = quantity - :quantity WHERE id = :variant_id AND inventory_item_id = :item_id');
            $update->execute(['quantity' => $deleteQuantity, 'variant_id' => $variantId, 'item_id' => $itemId]);
            inventoryRedirect('Quantity updated successfully.');
        }
    } elseif ($action === 'delete_item') {
        if (($_POST['delete_confirmation'] ?? '') !== 'Delete') {
            $errors['item'] = "Type 'Delete' exactly to confirm permanent deletion.";
            $modal = 'delete-modal';
        } else {
            try { $pdo->beginTransaction(); $pdo->prepare('DELETE FROM inventory_item_variants WHERE inventory_item_id = :id')->execute(['id' => $itemId]); $pdo->prepare('DELETE FROM inventory_items WHERE id = :id')->execute(['id' => $itemId]); $pdo->commit(); inventoryRedirect('Inventory item deleted successfully.'); } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors['item'] = 'Unable to delete the inventory item.'; }
        }
    }
}

$category = (int) ($_GET['category'] ?? 0); $search = trim($_GET['search'] ?? ''); $sort = $_GET['sort'] ?? 'newest'; $showOptions = ['10' => 10, '25' => 25, '50' => 50, '100' => 100, 'all' => 0]; $show = array_key_exists($_GET['show'] ?? '10', $showOptions) ? ($_GET['show'] ?? '10') : '10'; $page = max(1, (int) ($_GET['page'] ?? 1));
$sortMap = ['name_asc' => 'i.name ASC', 'name_desc' => 'i.name DESC', 'price_asc' => 'min_price ASC', 'price_desc' => 'min_price DESC', 'quantity_asc' => 'total_quantity ASC', 'quantity_desc' => 'total_quantity DESC', 'newest' => 'i.created_at DESC', 'oldest' => 'i.created_at ASC']; $sort = array_key_exists($sort, $sortMap) ? $sort : 'newest';
$sql = 'SELECT i.*, c.name AS category_name, COUNT(v.id) AS variant_count, MIN(v.price) AS min_price, MAX(v.price) AS max_price, COALESCE(SUM(v.quantity), 0) AS total_quantity FROM inventory_items i JOIN menu_categories c ON c.id = i.category_id LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id WHERE 1=1'; $params = []; if ($category) { $sql .= ' AND i.category_id = :category'; $params['category'] = $category; } if ($search !== '') { $sql .= ' AND (i.name LIKE :search_name OR c.name LIKE :search_category OR EXISTS (SELECT 1 FROM inventory_item_variants sv WHERE sv.inventory_item_id = i.id AND sv.sku LIKE :search_sku))'; $term = '%' . $search . '%'; $params['search_name'] = $term; $params['search_category'] = $term; $params['search_sku'] = $term; } $sql .= ' GROUP BY i.id ORDER BY ' . $sortMap[$sort]; $statement = $pdo->prepare($sql); $statement->execute($params); $allItems = $statement->fetchAll(); $totalItems = count($allItems); $variantLookup = []; if ($allItems) { $itemIds = array_column($allItems, 'id'); $placeholders = implode(',', array_fill(0, count($itemIds), '?')); $variantStatement = $pdo->prepare("SELECT * FROM inventory_item_variants WHERE inventory_item_id IN ($placeholders) ORDER BY FIELD(inventory_item_id, $placeholders), id ASC"); $variantStatement->execute([...$itemIds, ...$itemIds]); foreach ($variantStatement->fetchAll() as $variant) $variantLookup[$variant['inventory_item_id']][] = $variant; } $perPage = $showOptions[$show]; $totalPages = $perPage ? max(1, (int) ceil($totalItems / $perPage)) : 1; $page = min($page, $totalPages); $items = $perPage ? array_slice($allItems, ($page - 1) * $perPage, $perPage) : $allItems; $start = $totalItems ? ($perPage ? (($page - 1) * $perPage) + 1 : 1) : 0; $end = $totalItems ? ($perPage ? min($page * $perPage, $totalItems) : $totalItems) : 0; $queryParams = ['category' => $category, 'search' => $search, 'sort' => $sort, 'show' => $show];
$items = array_map(static function (array $inventoryItem): array { if (!empty($inventoryItem['photo_data']) && !empty($inventoryItem['photo_mime'])) $inventoryItem['photo'] = 'data:' . $inventoryItem['photo_mime'] . ';base64,' . base64_encode($inventoryItem['photo_data']); unset($inventoryItem['photo_data'], $inventoryItem['photo_mime']); return $inventoryItem; }, $items);
$editVariants = $formData['variants'] ?? [];

// Headline numbers for the summary tiles. Computed from the unfiltered catalogue
// so the tiles always describe the whole bakery, not the current page of results.
$statTotalItems = (int) $pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn();
$statTotalMenus = count($categories);
$statUnits = (int) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM inventory_item_variants')->fetchColumn();
$statLowStock = (int) $pdo->query('SELECT COUNT(*) FROM inventory_item_variants WHERE quantity > 0 AND quantity <= 10')->fetchColumn();
$statOutOfStock = (int) $pdo->query('SELECT COUNT(*) FROM inventory_item_variants WHERE quantity = 0')->fetchColumn();

require __DIR__ . '/../includes/staff_header.php';
?>
<section class="page-intro users-page-intro inventory-page-intro">
    <div>
        <span class="eyebrow">Bakery Catalog</span>
        <h2>Inventory</h2>
        <p>Manage BreadBreak products, menu categories, and stock.</p>
    </div>
    <button class="admin-button primary" type="button" data-open-staff-modal="add-item-modal"><i class="fa-solid fa-plus"></i> Add Item</button>
</section>
<?php if ($successMessage): ?><div class="admin-notice success" role="status"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?><?php if (!empty($errors['menu_delete'])): ?><div class="admin-notice danger" role="alert"><?php echo htmlspecialchars($errors['menu_delete']); ?></div><?php endif; ?><?php if (!empty($errors['item'])): ?><div class="admin-notice danger" role="alert"><?php echo htmlspecialchars($errors['item']); ?></div><?php endif; ?>

<section class="inv-stats" aria-label="Inventory overview">
    <article class="inv-stat">
        <span class="inv-stat-icon"><i class="fa-solid fa-bread-slice"></i></span>
        <div>
            <strong><?php echo $statTotalItems; ?></strong>
            <small>Products</small>
        </div>
    </article>
    <article class="inv-stat">
        <span class="inv-stat-icon"><i class="fa-solid fa-store"></i></span>
        <div>
            <strong><?php echo $statTotalMenus; ?></strong>
            <small>Menus</small>
        </div>
    </article>
    <article class="inv-stat">
        <span class="inv-stat-icon"><i class="fa-solid fa-boxes-stacked"></i></span>
        <div>
            <strong><?php echo $statUnits; ?></strong>
            <small>Units in stock</small>
        </div>
    </article>
    <article class="inv-stat<?php echo $statLowStock ? ' is-warn' : ''; ?>">
        <span class="inv-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div>
            <strong><?php echo $statLowStock; ?></strong>
            <small>Low stock</small>
        </div>
    </article>
    <article class="inv-stat<?php echo $statOutOfStock ? ' is-danger' : ''; ?>">
        <span class="inv-stat-icon"><i class="fa-solid fa-circle-xmark"></i></span>
        <div>
            <strong><?php echo $statOutOfStock; ?></strong>
            <small>Out of stock</small>
        </div>
    </article>
</section>

<section class="panel menu-panel inventory-section"><div class="panel-heading"><div><h3>Menu</h3><p class="menu-description">Organize bakery items by menu category.</p></div></div><div class="category-grid"><a class="category-card <?php echo !$category ? 'active' : ''; ?>" href="?<?php echo http_build_query(['search' => $search, 'sort' => $sort, 'show' => $show]); ?>">All Items</a><?php foreach ($categories as $menu): ?><div class="category-card <?php echo $category === (int) $menu['id'] ? 'active' : ''; ?>"><a href="?<?php echo http_build_query(['category' => $menu['id'], 'search' => $search, 'sort' => $sort, 'show' => $show]); ?>"><?php echo htmlspecialchars($menu['name']); ?></a><button class="category-actions dots-button" type="button" aria-label="Actions for <?php echo htmlspecialchars($menu['name']); ?>" aria-expanded="false">&#8942;</button><div class="action-menu"><button type="button" data-menu-action="edit" data-menu-id="<?php echo (int) $menu['id']; ?>" data-menu-name="<?php echo htmlspecialchars($menu['name'], ENT_QUOTES); ?>" data-menu-has-photo="<?php echo ((int) ($menu['has_photo'] ?? 0)) ? '1' : '0'; ?>">Edit Menu</button><button type="button" data-menu-action="add_size" data-menu-id="<?php echo (int) $menu['id']; ?>" data-menu-name="<?php echo htmlspecialchars($menu['name'], ENT_QUOTES); ?>">+ Add Size / Variant</button><button type="button" class="danger-text" data-menu-action="delete" data-menu-id="<?php echo (int) $menu['id']; ?>" data-menu-name="<?php echo htmlspecialchars($menu['name'], ENT_QUOTES); ?>">Delete Menu</button></div></div><?php endforeach; ?></div><div class="menu-add-row"><button class="admin-button secondary" type="button" data-open-staff-modal="add-size-modal">+ Add Service Size to Menu</button><button class="admin-button secondary" type="button" data-open-staff-modal="add-menu-modal">+ Add New to Menu</button></div></section>
<section class="panel inventory-section"><div class="panel-heading"><div><h3>Inventory Items</h3><p class="view-description">Switch between a detailed list and a visual product grid.</p></div><div class="inventory-view-toggle" role="group" aria-label="Inventory view"><button class="view-toggle-button is-active" type="button" data-inventory-view="list" aria-pressed="true" title="List view">List</button><button class="view-toggle-button" type="button" data-inventory-view="grid" aria-pressed="false" title="Grid view">Grid</button></div></div><form class="inventory-controls" method="GET"><input type="hidden" name="category" value="<?php echo $category; ?>" /><div class="search-control"><label class="sr-only" for="inventory-search">Search inventory</label><input id="inventory-search" name="search" type="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search inventory..." /><button class="admin-button secondary" type="submit">Search</button></div><label class="select-control">Sort by<select name="sort" onchange="this.form.submit()"><option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option><option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option><option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option><option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price (Low to High)</option><option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price (High to Low)</option><option value="quantity_asc" <?php echo $sort === 'quantity_asc' ? 'selected' : ''; ?>>Quantity (Low to High)</option><option value="quantity_desc" <?php echo $sort === 'quantity_desc' ? 'selected' : ''; ?>>Quantity (High to Low)</option><option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option></select></label><label class="select-control">Show<select name="show" onchange="this.form.submit()"><option value="10" <?php echo $show === '10' ? 'selected' : ''; ?>>10</option><option value="25" <?php echo $show === '25' ? 'selected' : ''; ?>>25</option><option value="50" <?php echo $show === '50' ? 'selected' : ''; ?>>50</option><option value="100" <?php echo $show === '100' ? 'selected' : ''; ?>>100</option><option value="all" <?php echo $show === 'all' ? 'selected' : ''; ?>>All</option></select></label></form><div class="inventory-view inventory-list-view" data-inventory-view-content="list"><div class="table-wrap"><table class="admin-table inventory-table"><thead><tr><th>Item</th><th>Category</th><th>Variants</th><th>Price Range</th><th>Stock</th><th>Availability</th><th>Actions</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td><td><?php echo htmlspecialchars($item['category_name']); ?></td><td><?php echo (int) $item['variant_count']; ?> <?php echo (int) $item['variant_count'] === 1 ? 'size' : 'sizes'; ?></td><td>&#8369;<?php echo number_format((float) $item['min_price'], 2); ?><?php if ((float) $item['min_price'] !== (float) $item['max_price']): ?> &ndash; &#8369;<?php echo number_format((float) $item['max_price'], 2); ?><?php endif; ?></td><td><?php echo (int) $item['total_quantity']; ?> total</td><td><span class="availability-badge">View variants</span></td><td class="actions-cell"><button class="dots-button" type="button" aria-label="Actions for <?php echo htmlspecialchars($item['name']); ?>" aria-expanded="false">&#8942;</button><div class="action-menu"><button type="button" data-item-action="view" data-item-id="<?php echo (int) $item['id']; ?>" data-item-variants="<?php echo htmlspecialchars(json_encode($variantLookup[$item['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>" data-item-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>" data-item-category="<?php echo htmlspecialchars($item['category_name'], ENT_QUOTES); ?>" data-item-description="<?php echo htmlspecialchars($item['description'], ENT_QUOTES); ?>">View Item</button><button type="button" data-item-action="edit" data-item-id="<?php echo (int) $item['id']; ?>" data-item-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>" data-item-category-id="<?php echo (int) $item['category_id']; ?>" data-item-description="<?php echo htmlspecialchars($item['description'], ENT_QUOTES); ?>" data-item-variants="<?php echo htmlspecialchars(json_encode($variantLookup[$item['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>">Edit Item</button><button class="danger-text" type="button" data-item-action="delete" data-item-id="<?php echo (int) $item['id']; ?>">Delete Item</button></div></td></tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="7" class="empty-state">No inventory items found.</td></tr><?php endif; ?></tbody></table></div></div><div class="inventory-view inventory-grid-view" data-inventory-view-content="grid"><div class="inventory-card-grid"><?php foreach ($items as $item): ?><article class="inventory-item-card"><?php $cardStock = inventoryStockStatus((int) $item['total_quantity']); ?><div class="inventory-card-image"><?php if (!empty($item['photo'])): ?><img src="<?php echo htmlspecialchars($item['photo']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" loading="lazy" /><?php else: ?><span>No photo</span><?php endif; ?><span class="stock-badge <?php echo $cardStock[1]; ?>"><?php echo $cardStock[0]; ?></span></div><div class="inventory-card-content"><div class="inventory-card-heading"><div><span class="inventory-card-category"><?php echo htmlspecialchars($item['category_name']); ?></span><h4><?php echo htmlspecialchars($item['name']); ?></h4></div><div class="actions-cell"><button class="dots-button" type="button" aria-label="Actions for <?php echo htmlspecialchars($item['name']); ?>" aria-expanded="false">&#8942;</button><div class="action-menu"><button type="button" data-item-action="view" data-item-id="<?php echo (int) $item['id']; ?>" data-item-variants="<?php echo htmlspecialchars(json_encode($variantLookup[$item['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>" data-item-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>" data-item-category="<?php echo htmlspecialchars($item['category_name'], ENT_QUOTES); ?>" data-item-description="<?php echo htmlspecialchars($item['description'], ENT_QUOTES); ?>">View Item</button><button type="button" data-item-action="edit" data-item-id="<?php echo (int) $item['id']; ?>" data-item-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>" data-item-category-id="<?php echo (int) $item['category_id']; ?>" data-item-description="<?php echo htmlspecialchars($item['description'], ENT_QUOTES); ?>" data-item-variants="<?php echo htmlspecialchars(json_encode($variantLookup[$item['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>">Edit Item</button><button class="danger-text" type="button" data-item-action="delete" data-item-id="<?php echo (int) $item['id']; ?>">Delete Item</button></div></div></div><div class="inventory-card-meta"><span><?php echo (int) $item['variant_count']; ?> <?php echo (int) $item['variant_count'] === 1 ? 'size' : 'sizes'; ?></span><span><?php echo (int) $item['total_quantity']; ?> total stock</span></div><strong class="inventory-card-price">&#8369;<?php echo number_format((float) $item['min_price'], 2); ?><?php if ((float) $item['min_price'] !== (float) $item['max_price']): ?> &ndash; &#8369;<?php echo number_format((float) $item['max_price'], 2); ?><?php endif; ?></strong></div></article><?php endforeach; ?><?php if (!$items): ?><p class="empty-state">No inventory items found.</p><?php endif; ?></div></div><div class="list-footer"><span>Showing <?php echo $start; ?>&ndash;<?php echo $end; ?> of <?php echo $totalItems; ?> items</span><?php if ($totalPages > 1 && $show !== 'all'): ?><nav class="pagination" aria-label="Inventory pages"><?php $previous = $queryParams; $previous['page'] = max(1, $page - 1); ?><a class="page-link <?php echo $page === 1 ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($previous); ?>">Previous</a><?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): $pageQuery = $queryParams; $pageQuery['page'] = $pageNumber; ?><a class="page-link <?php echo $pageNumber === $page ? 'current' : ''; ?>" href="?<?php echo http_build_query($pageQuery); ?>"><?php echo $pageNumber; ?></a><?php endfor; $next = $queryParams; $next['page'] = min($totalPages, $page + 1); ?><a class="page-link <?php echo $page === $totalPages ? 'disabled' : ''; ?>" href="?<?php echo http_build_query($next); ?>">Next</a></nav><?php endif; ?></div></section>

<div class="modal-backdrop <?php echo $modal ? 'is-open' : ''; ?>" data-modal-backdrop></div>
<div class="admin-modal <?php echo $modal === 'add-item-modal' ? 'is-open' : ''; ?>" id="add-item-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Stock Management</span><h2>Add Inventory Item</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><?php $formAction = 'create_item'; $itemFormId = ''; require __DIR__ . '/inventory_form.php'; ?></div>
<div class="admin-modal <?php echo $modal === 'edit-item-modal' ? 'is-open' : ''; ?>" id="edit-item-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Stock Management</span><h2>Edit Inventory Item</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><form method="POST" class="admin-form" data-inventory-form="edit"><input type="hidden" name="action" value="edit_item" /><input class="item-id" type="hidden" name="item_id" /><div class="form-grid two"><div class="form-field"><label for="edit-name">Item Name</label><input id="edit-name" name="name" required /></div><div class="form-field"><label for="edit-category">Category Menu</label><select id="edit-category" name="category_id" required><option value="">Select Category Menu</option><?php foreach ($categories as $menu): ?><option value="<?php echo (int) $menu['id']; ?>"><?php echo htmlspecialchars($menu['name']); ?></option><?php endforeach; ?></select></div></div><div class="form-field"><label for="edit-description">Description</label><textarea id="edit-description" name="description" required></textarea></div><div class="photo-field"><label for="edit-photo">Photo</label><label class="photo-drop" for="edit-photo"><strong>Attach Photo</strong><small>JPG, PNG, WEBP</small><span class="photo-filename" data-photo-name="edit">No photo selected</span></label><input id="edit-photo" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input="edit" /></div><h3 class="variant-section-title">Service Size &amp; Variants</h3><div class="variant-list" data-variant-list="edit"></div><small class="field-error variant-form-error"><?php echo htmlspecialchars($errors['variants'] ?? $errors['sku'] ?? ''); ?></small><button class="admin-button secondary add-variant" type="button" data-add-variant="edit">+ Add Variant</button><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit">Save Changes</button></div></form></div>
<div class="admin-modal compact <?php echo $modal === 'add-menu-modal' ? 'is-open' : ''; ?>" id="add-menu-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Menu</span><h2>Add New Menu</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><form method="POST" enctype="multipart/form-data" class="admin-form"><input type="hidden" name="action" value="create_menu" /><div class="form-field"><label for="menu-name">Menu Name</label><input id="menu-name" name="menu_name" placeholder="Enter menu name" required /><?php if (isset($errors['menu_name'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['menu_name']); ?></small><?php endif; ?></div><div class="photo-field"><label for="menu-photo-add">Cover Photo</label><label class="photo-drop" for="menu-photo-add"><strong>Attach Photo</strong><small>JPG, PNG, WEBP</small><span class="photo-filename" data-photo-name="menu-add">No photo selected</span></label><input id="menu-photo-add" class="sr-only" name="menu_photo" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input="menu-add" /><small class="field-help">Shown on the Shop landing tiles. Leave empty to use a product photo.</small><?php if (isset($errors['menu_photo'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['menu_photo']); ?></small><?php endif; ?></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit">Add Menu</button></div></form></div>
<div class="admin-modal compact <?php echo $modal === 'edit-menu-modal' ? 'is-open' : ''; ?>" id="edit-menu-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Menu</span><h2>Edit Menu</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><form method="POST" enctype="multipart/form-data" class="admin-form"><input type="hidden" name="action" value="edit_menu" /><input class="menu-id" type="hidden" name="category_id" /><div class="form-field"><label for="edit-menu-name">Menu Name</label><input id="edit-menu-name" name="menu_name" required /><?php if (isset($errors['menu_name'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['menu_name']); ?></small><?php endif; ?></div><div class="photo-field"><label for="menu-photo-edit">Cover Photo</label><label class="photo-drop" for="menu-photo-edit"><strong>Attach Photo</strong><small>JPG, PNG, WEBP</small><span class="photo-filename" data-photo-name="menu-edit">No photo selected</span></label><input id="menu-photo-edit" class="sr-only" name="menu_photo" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input="menu-edit" /><small class="field-help">Pick a new photo to replace the current one.</small><?php if (isset($errors['menu_photo'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['menu_photo']); ?></small><?php endif; ?></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit">Save Changes</button></div></form></div>
<div class="admin-modal compact <?php echo $modal === 'delete-menu-modal' ? 'is-open' : ''; ?>" id="delete-menu-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker warning">Menu</span><h2>Delete Menu</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><p>Are you sure you want to delete this menu category?</p><?php if (isset($errors['menu_delete'])): ?><div class="admin-notice danger" role="alert"><?php echo htmlspecialchars($errors['menu_delete']); ?></div><?php endif; ?><form method="POST" class="admin-form"><input type="hidden" name="action" value="delete_menu" /><input class="menu-id" type="hidden" name="category_id" /><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button danger-button" type="submit">Delete Menu</button></div></form></div>

<div class="admin-modal compact <?php echo $modal === 'add-size-modal' ? 'is-open' : ''; ?>" id="add-size-modal" role="dialog" aria-modal="true">
    <div class="modal-heading">
        <div>
            <span class="modal-kicker">Menu Category</span>
            <h2>Add Service Size &amp; Variant</h2>
        </div>
        <button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="POST" class="admin-form">
        <input type="hidden" name="action" value="create_category_size" />
        <div class="form-field">
            <label for="size-category">Menu Category</label>
            <select id="size-category" name="category_id" required>
                <option value="">Select Category</option>
                <?php foreach ($categories as $menu): ?>
                    <option value="<?php echo (int) $menu['id']; ?>"><?php echo htmlspecialchars($menu['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="size-name">Service Size / Variant Name</label>
            <input id="size-name" name="size_name" placeholder="e.g. 12-inch round, 6pcs box, etc." required />
            <?php if (isset($errors['size_name'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['size_name']); ?></small><?php endif; ?>
        </div>
        <div class="form-field">
            <label>Current Sizes in Selected Category</label>
            <div class="category-sizes-tags" id="category-sizes-preview">Select a category to view sizes</div>
        </div>
        <div class="modal-actions">
            <button class="admin-button secondary" type="button" data-close-modal>Cancel</button>
            <button class="admin-button primary" type="submit">Add Service Size</button>
        </div>
    </form>
</div>

<div class="admin-modal view-item-modal" id="view-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker">Inventory Details</span><h2>View Item</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><div class="item-overview"><div class="item-photo-placeholder">No photo available</div><dl class="item-detail-list"><div><dt>Item</dt><dd data-detail="name"></dd></div><div><dt>Category</dt><dd data-detail="category"></dd></div><div><dt>Description</dt><dd data-detail="description"></dd></div></dl></div><h3 class="variant-section-title">Service Size Variants</h3><div class="table-wrap variant-table-wrap"><table class="admin-table variant-detail-table"><thead><tr><th>Service Size</th><th>SKU</th><th>Price</th><th>Quantity</th><th>Stock Status</th><th>Availability</th></tr></thead><tbody data-variant-details></tbody></table></div><div class="view-summary"><strong>Total Stock: <span data-detail="total-stock"></span></strong><strong>Price Range: <span data-detail="price-range"></span></strong></div><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Close</button></div></div>
<div class="admin-modal compact warning-modal" id="delete-modal" role="dialog" aria-modal="true"><div class="modal-heading"><div><span class="modal-kicker warning">Inventory Action</span><h2>Delete Inventory Item</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button></div><p>Choose what you want to delete.</p><form method="POST" class="admin-form" data-delete-form><input type="hidden" name="action" value="delete_quantity" /><input class="item-id" type="hidden" name="item_id" /><input type="hidden" name="delete_confirmation" value="" data-delete-confirmation-value /><div class="delete-choice"><label><input type="radio" name="delete_mode" value="quantity" checked /> Delete Quantity</label><label><input type="radio" name="delete_mode" value="item" /> Delete Entire Item</label></div><div class="delete-mode-content" data-delete-content="quantity"><div class="form-field"><label>Item</label><strong data-delete-item-name></strong></div><div class="form-field"><label for="delete-variant">Service Size</label><select id="delete-variant" name="variant_id" data-delete-variant required></select></div><div class="delete-quantity-grid"><div class="form-field"><label>Current Quantity</label><output data-current-quantity>0</output></div><div class="form-field"><label for="delete-quantity">Quantity to Delete</label><input id="delete-quantity" name="delete_quantity" type="number" min="1" step="1" required /></div><div class="form-field"><label>Remaining Quantity</label><output data-remaining-quantity>0</output></div></div><small class="field-error" data-delete-error><?php echo htmlspecialchars($errors['delete_quantity'] ?? ''); ?></small><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit" data-delete-quantity-button>Update Quantity</button></div></div><div class="delete-mode-content" data-delete-content="item" hidden><span class="modal-kicker warning">Permanent Action</span><p>You are about to permanently delete this inventory item, including all of its Service Size variants, SKUs, prices, and stock quantities.</p><p>This action cannot be undone.</p><strong class="delete-item-name" data-delete-item-name></strong><p>To confirm, type 'Delete' below.</p><input id="delete-confirmation" type="text" placeholder="Type 'Delete' to confirm" autocomplete="off" /><div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button danger-button" type="submit" data-delete-entire-button disabled>Delete Item</button></div></div></form></div>

<script>
window.categorySizes = <?php echo json_encode($allCategorySizes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.categoryNames = <?php echo json_encode($categoryNameMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
