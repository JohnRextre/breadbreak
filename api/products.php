<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDatabaseConnection();
    // Photos are streamed from api/product-image.php so this payload stays light.
    $statement = $pdo->query("SELECT i.id, i.name, i.description, i.category_id, c.name AS category_name, v.id AS variant_id, v.service_size, v.price, v.quantity, v.availability FROM inventory_items i JOIN menu_categories c ON c.id = i.category_id JOIN inventory_item_variants v ON v.inventory_item_id = i.id WHERE v.availability = 'available' AND v.quantity > 0 ORDER BY i.name ASC, v.id ASC");
    $products = [];
    foreach ($statement->fetchAll() as $row) {
        $id = (int) $row['id'];
        if (!isset($products[$id])) {
            $products[$id] = [
                'id' => $id,
                'name' => $row['name'],
                'description' => $row['description'],
                'category' => strtolower(str_replace(' ', '-', $row['category_name'])),
                'category_id' => (int) $row['category_id'],
                'categoryName' => $row['category_name'],
                'photo' => '/BreadBreak/api/product-image.php?id=' . $id,
                'variants' => [],
            ];
        }
        $products[$id]['variants'][] = ['id' => (int) $row['variant_id'], 'service_size' => $row['service_size'], 'price' => (float) $row['price'], 'quantity' => (int) $row['quantity']];
    }
    echo json_encode(['products' => array_values($products)]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['products' => [], 'error' => 'Products are temporarily unavailable.']);
}
