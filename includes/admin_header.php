<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? 'Admin Panel | BreadBreak';
$activePage = $activePage ?? basename($_SERVER['PHP_SELF'], '.php');
$adminName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$adminName = $adminName !== '' ? $adminName : 'Admin';
$adminInitial = strtoupper(substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="BreadBreak administration panel." />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css" />
</head>
<body class="admin-body">
    <div class="admin-app">
        <?php require __DIR__ . '/admin_sidebar.php'; ?>
        <div class="admin-main-wrap">
            <header class="admin-topbar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <div class="topbar-title">
                    <span class="topbar-kicker">BreadBreak / Admin</span>
                    <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                </div>
                <div class="admin-identity">
                    <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars($adminInitial); ?></div>
                    <div class="identity-copy">
                        <strong><?php echo htmlspecialchars($adminName); ?></strong>
                        <span>Administrator</span>
                    </div>
                </div>
            </header>
            <main class="admin-content">
