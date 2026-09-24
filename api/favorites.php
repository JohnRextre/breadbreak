<?php
// Heart / favorites for signed-in customers.
//   GET  action=list    -> { signed_in: bool, favorites: [product_id, ...] }
//   POST action=toggle  -> { favorite: bool }
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$isCustomer = ($_SESSION['role'] ?? '') === 'customer';
$customerId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_REQUEST['action'] ?? 'list');

try {
    $pdo = getDatabaseConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_favorites (customer_id INT NOT NULL, inventory_item_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (customer_id, inventory_item_id), CONSTRAINT fk_fav_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_fav_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $favoriteIds = static function (PDO $pdo, int $customerId): array {
        $statement = $pdo->prepare('SELECT inventory_item_id FROM customer_favorites WHERE customer_id = :cid ORDER BY created_at DESC');
        $statement->execute(['cid' => $customerId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    };

    if (!$isCustomer || $customerId <= 0) {
        if ($action === 'toggle') {
            http_response_code(401);
            echo json_encode(['favorite' => false, 'signed_in' => false, 'message' => 'Sign in to save favorites.']);
            exit;
        }
        echo json_encode(['signed_in' => false, 'favorites' => []]);
        exit;
    }

    if ($action === 'toggle') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        if (!$productId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing product_id']);
            exit;
        }

        $productStatement = $pdo->prepare('SELECT id FROM inventory_items WHERE id = :id LIMIT 1');
        $productStatement->execute(['id' => $productId]);
        if (!$productStatement->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            exit;
        }

        $checkStatement = $pdo->prepare('SELECT 1 FROM customer_favorites WHERE customer_id = :cid AND inventory_item_id = :pid LIMIT 1');
        $checkStatement->execute(['cid' => $customerId, 'pid' => $productId]);
        $isFavorite = (bool) $checkStatement->fetchColumn();

        if ($isFavorite) {
            $pdo->prepare('DELETE FROM customer_favorites WHERE customer_id = :cid AND inventory_item_id = :pid')
                ->execute(['cid' => $customerId, 'pid' => $productId]);
        } else {
            $pdo->prepare('INSERT INTO customer_favorites (customer_id, inventory_item_id) VALUES (:cid, :pid)')
                ->execute(['cid' => $customerId, 'pid' => $productId]);
        }

        echo json_encode(['favorite' => !$isFavorite, 'signed_in' => true, 'favorites' => $favoriteIds($pdo, $customerId)]);
        exit;
    }

    echo json_encode(['signed_in' => true, 'favorites' => $favoriteIds($pdo, $customerId)]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Favorites are temporarily unavailable.']);
}
