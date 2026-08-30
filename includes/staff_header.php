<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'Staff Portal | BreadBreak';
$activePage = $activePage ?? basename($_SERVER['PHP_SELF'], '.php');
$staffName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$staffName = $staffName !== '' ? $staffName : 'Staff';
$staffInitial = strtoupper(substr($staffName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="BreadBreak staff portal." />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/staff.css" />
</head>
<body class="admin-body staff-body">
    <div class="admin-app">
        <?php require __DIR__ . '/staff_sidebar.php'; ?>
        <div class="admin-main-wrap">
            <header class="admin-topbar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false"><span></span><span></span><span></span></button>
                <div class="topbar-title"><span class="topbar-kicker">BreadBreak / Staff</span><h1><?php echo htmlspecialchars($pageTitle); ?></h1></div>
                <div class="admin-identity"><div class="avatar" aria-hidden="true"><?php echo htmlspecialchars($staffInitial); ?></div><div class="identity-copy"><strong><?php echo htmlspecialchars($staffName); ?></strong><span>Staff</span></div></div>
            </header>
            <main class="admin-content">
