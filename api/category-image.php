<?php
// Serves a menu-category image: staff-uploaded photo first, otherwise a
// representative product photo from that category, otherwise the bakery logo.
require_once __DIR__ . '/../config/database.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    exit;
}

$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
];

$sendFile = static function (string $path, array $mimeMap): void {
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = $mimeMap[$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=604800');
    readfile($path);
};

$sendBlob = static function (?string $data, ?string $mime): void {
    if (!empty($data) && !empty($mime)) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: public, max-age=604800');
        echo $data;
        exit;
    }
};

try {
    $pdo = getDatabaseConnection();

    // Category tables may not carry photos yet on older installs.
    $hasPhotoColumns = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'menu_categories' AND column_name IN ('photo_data','photo_mime')")->fetchColumn();

    if ($hasPhotoColumns >= 2) {
        $statement = $pdo->prepare('SELECT photo_data, photo_mime FROM menu_categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $category = $statement->fetch();
        $sendBlob($category['photo_data'] ?? null, $category['photo_mime'] ?? null);
    }

    // Fallback: photo of the newest product inside this category.
    $fallbackStatement = $pdo->prepare(
        "SELECT i.photo_data, i.photo_mime, i.photo
         FROM inventory_items i
         WHERE i.category_id = :id
         ORDER BY i.id ASC
         LIMIT 1"
    );
    $fallbackStatement->execute(['id' => $id]);
    $fallbackItem = $fallbackStatement->fetch();

    if ($fallbackItem) {
        $sendBlob($fallbackItem['photo_data'] ?? null, $fallbackItem['photo_mime'] ?? null);

        $photo = trim((string) ($fallbackItem['photo'] ?? ''));
        if ($photo !== '' && !preg_match('#^https?://#i', $photo)) {
            $relative = preg_replace('#^/?(BreadBreak/)?#', '', ltrim($photo, '/'));
            $candidate = dirname(__DIR__) . '/' . $relative;
            if (is_file($candidate)) {
                $sendFile($candidate, $mimeMap);
                exit;
            }
        }
    }
} catch (Throwable $exception) {
    // Fall through to the placeholder so category tiles never show a broken image.
}

$fallback = __DIR__ . '/../assets/breadbreak_png/breadbreak_logo.png';
if (is_file($fallback)) {
    $sendFile($fallback, $mimeMap);
    exit;
}

http_response_code(404);
