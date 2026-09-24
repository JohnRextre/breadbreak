<?php
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

try {
    $pdo = getDatabaseConnection();
    $statement = $pdo->prepare('SELECT photo, photo_data, photo_mime FROM inventory_items WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $item = $statement->fetch();

    if ($item && !empty($item['photo_data']) && !empty($item['photo_mime'])) {
        header('Content-Type: ' . $item['photo_mime']);
        header('Content-Length: ' . strlen($item['photo_data']));
        header('Cache-Control: public, max-age=604800');
        echo $item['photo_data'];
        exit;
    }

    if ($item && !empty($item['photo'])) {
        $photo = trim($item['photo']);

        if (preg_match('#^https?://#i', $photo)) {
            header('Location: ' . $photo);
            exit;
        }

        // Accept paths written as "/BreadBreak/assets/...", "assets/..." or absolute paths.
        $relative = preg_replace('#^/?(BreadBreak/)?#', '', ltrim($photo, '/'));
        $candidate = dirname(__DIR__) . '/' . $relative;

        if (is_file($candidate)) {
            $sendFile($candidate, $mimeMap);
            exit;
        }
    }
} catch (Throwable $exception) {
    // Fall through to the placeholder below so product cards never show a broken image.
}

$fallback = __DIR__ . '/../assets/breadbreak_png/breadbreak_logo.png';
if (is_file($fallback)) {
    $sendFile($fallback, $mimeMap);
    exit;
}

http_response_code(404);
