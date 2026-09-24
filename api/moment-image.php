<?php
require_once __DIR__ . '/../config/database.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $statement = $pdo->prepare('SELECT photo_data, photo_mime FROM bread_moment_photos WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $photo = $statement->fetch();

    if ($photo && !empty($photo['photo_data']) && !empty($photo['photo_mime'])) {
        header('Content-Type: ' . $photo['photo_mime']);
        header('Content-Length: ' . strlen($photo['photo_data']));
        header('Cache-Control: public, max-age=604800');
        echo $photo['photo_data'];
        exit;
    }
} catch (Throwable $exception) {
    // Fall through to the placeholder so feed cards never show a broken image.
}

$fallback = __DIR__ . '/../assets/breadbreak_png/breadbreak_logo.png';
if (is_file($fallback)) {
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($fallback));
    readfile($fallback);
    exit;
}

http_response_code(404);
