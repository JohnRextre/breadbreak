<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

$pageTitle = $pageTitle ?? 'Rider Portal | BreadBreak';
$riderChatMode = !empty($riderChatMode);
$riderAccount = [];
if (!empty($_SESSION['user_id'])) {
    $riderStmt = getDatabaseConnection()->prepare(
        'SELECT first_name, last_name, email, phone FROM users WHERE id = :id AND role = :role LIMIT 1'
    );
    $riderStmt->execute(['id' => (int) $_SESSION['user_id'], 'role' => 'rider']);
    $riderAccount = $riderStmt->fetch() ?: [];
    if ($riderAccount) {
        $_SESSION['first_name'] = $riderAccount['first_name'];
        $_SESSION['last_name'] = $riderAccount['last_name'];
        $_SESSION['email'] = $riderAccount['email'];
    }
}
$riderName = trim(($riderAccount['first_name'] ?? $_SESSION['first_name'] ?? '') . ' ' . ($riderAccount['last_name'] ?? $_SESSION['last_name'] ?? ''));
$riderName = $riderName !== '' ? $riderName : 'Rider';
$riderInitial = strtoupper(substr($riderName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/rider.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/rider.css') ? filemtime(__DIR__ . '/../assets/css/rider.css') : time(); ?>" />
</head>
<body class="rider-body<?php echo $riderChatMode ? ' is-chat' : ''; ?>">
    <header class="rider-topbar">
        <a href="<?php echo BASE_URL; ?>/rider/dashboard.php" class="rider-brand">
            <img src="<?php echo BASE_URL; ?>/assets/breadbreak_png/breadbreak_logo.png" alt="BreadBreak" width="40" height="40" />
            <span>
                <strong>BreadBreak</strong>
                <small>Rider</small>
            </span>
        </a>
        <div class="rider-identity">
            <span class="rider-avatar"><?php echo htmlspecialchars($riderInitial); ?></span>
            <span><?php echo htmlspecialchars($riderName); ?></span>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="rider-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </header>
    <main class="rider-main<?php echo $riderChatMode ? ' rider-main-chat' : ''; ?>">
