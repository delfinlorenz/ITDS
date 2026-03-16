<?php
requireLogin();
$notifCount  = getUnreadNotificationCount();
$user        = currentUser();
$currentPage = $currentPage ?? '';
$base        = baseUrl();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — <?= sanitize($pageTitle ?? 'Dashboard') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;400;600;700&display=swap" rel="stylesheet">
    <meta name="base-url" content="<?= $base ?>">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
</head>
<body>
<div class="app">

    <!-- ====== SIDEBAR ====== -->
    <aside class="sidebar">
        <div class="logo-area">
            <div class="logo-icon"><i class="fas fa-microchip"></i></div>
            <span class="logo-text"><?= APP_NAME ?></span>
        </div>

        <?php
        $navItems = [
            ['page' => 'dashboard',   'icon' => 'chart-pie',     'label' => 'Dashboard'],
            ['page' => 'inventory',   'icon' => 'server',        'label' => 'Inventory'],
            ['page' => 'supplies',    'icon' => 'boxes',         'label' => 'Extra Supplies'],
            ['page' => 'employees',   'icon' => 'users',         'label' => 'Employees'],
            ['page' => 'maintenance', 'icon' => 'tools',         'label' => 'Maintenance'],
            ['page' => 'history',     'icon' => 'history',       'label' => 'History'],
            ['page' => 'software',    'icon' => 'code',          'label' => 'Software & Licenses'],
            ['page' => 'network',     'icon' => 'network-wired', 'label' => 'Network Devices'],
            ['page' => 'helpdesk',    'icon' => 'headset',       'label' => 'Helpdesk'],
            ['page' => 'performance', 'icon' => 'tachometer-alt','label' => 'Performance'],
            ['page' => 'security',    'icon' => 'shield-alt',    'label' => 'Security'],
            ['page' => 'gallery',     'icon' => 'images',        'label' => 'Gallery'],
            ['page' => 'audit',       'icon' => 'list-alt',      'label' => 'Audit Trail'],
            ['page' => 'forecast',    'icon' => 'chart-bar',     'label' => 'Forecasting'],
        ];

        foreach ($navItems as $item):
            if (in_array($item['page'], ['audit','forecast']) && !hasRole('Admin','Manager','Auditor')) continue;
            $active = ($currentPage === $item['page']) ? 'active' : '';
        ?>
        <a class="nav-item <?= $active ?>" href="<?= $base ?>/pages/<?= $item['page'] ?>.php">
            <i class="fas fa-<?= $item['icon'] ?>"></i>
            <span><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>

        <?php if (hasRole('Admin')): ?>
        <a class="nav-item <?= $currentPage==='users'?'active':'' ?>" href="<?= $base ?>/pages/users.php">
            <i class="fas fa-user-cog"></i>
            <span>User Management</span>
        </a>
        <?php endif; ?>

        <div class="theme-toggle">
            <i class="fas fa-sun"></i>
            <label class="switch">
                <input type="checkbox" id="darkmodeToggle">
                <span class="slider"></span>
            </label>
            <i class="fas fa-moon"></i>
        </div>
    </aside>

    <!-- ====== MAIN ====== -->
    <main class="main">

        <!-- Top Bar -->
        <div class="top-bar">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="globalSearch" placeholder="Search assets, employees, serial numbers..."
                       autocomplete="off" onkeyup="handleSmartSearch(this.value)">
                <div class="suggestions-box" id="suggestions"></div>
            </div>

            <div class="user-info">
                <div class="notif-bell" onclick="window.location='<?= $base ?>/pages/notifications.php'">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?>
                    <span class="notif-badge"><?= $notifCount ?></span>
                    <?php endif; ?>
                </div>

                <div class="avatar" onclick="toggleLogout()">
                    <?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?>
                    <div class="logout-dropdown" id="logoutDropdown">
                        <div class="logout-item">
                            <i class="fas fa-user"></i>
                            <span><?= sanitize($user['name'] ?? '') ?> (<?= sanitize($user['role'] ?? '') ?>)</span>
                        </div>
                        <a class="logout-item" href="<?= $base ?>/pages/profile.php">
                            <i class="fas fa-id-card"></i>
                            <span>My Profile</span>
                        </a>
                        <a class="logout-item danger" href="<?= $base ?>/logout.php">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Page Content -->
        <div class="page-content">
