<?php
// ============================================================
//  Role-Based Navbar - CraftHub Organizer
// ============================================================

$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

$navLinks = [];

if ($_SESSION['user_role'] === 'admin') {
    $navLinks = [
        ['href' => APP_URL . '/admin/dashboard.php',   'icon' => 'fa-gauge',       'label' => 'Dashboard'],
        ['href' => APP_URL . '/admin/packages.php',    'icon' => 'fa-box-open',    'label' => 'Packages'],
        ['href' => APP_URL . '/admin/components.php',  'icon' => 'fa-puzzle-piece','label' => 'Components'],
        ['href' => APP_URL . '/admin/venues.php',      'icon' => 'fa-location-dot','label' => 'Venues'],
        ['href' => APP_URL . '/admin/bookings.php',    'icon' => 'fa-calendar-check','label' => 'Bookings'],
        ['href' => APP_URL . '/admin/users.php',       'icon' => 'fa-users',       'label' => 'Users'],
        ['href' => APP_URL . '/admin/reports.php',     'icon' => 'fa-chart-bar',   'label' => 'Reports'],
        ['href' => APP_URL . '/admin/audit.php',       'icon' => 'fa-shield-halved','label' => 'Audit Log'],
    ];
} elseif ($_SESSION['user_role'] === 'cashier') {
    $navLinks = [
        ['href' => APP_URL . '/cashier/dashboard.php',       'icon' => 'fa-gauge',       'label' => 'Dashboard'],
        ['href' => APP_URL . '/cashier/payments.php',        'icon' => 'fa-money-bill-wave','label' => 'Payments'],
        ['href' => APP_URL . '/cashier/collection.php',      'icon' => 'fa-receipt',     'label' => 'Collection'],
    ];
} elseif ($_SESSION['user_role'] === 'customer') {
    $navLinks = [
        ['href' => APP_URL . '/customer/dashboard.php',      'icon' => 'fa-gauge',       'label' => 'Dashboard'],
        ['href' => APP_URL . '/customer/packages.php',       'icon' => 'fa-box-open',    'label' => 'Browse Packages'],
        ['href' => APP_URL . '/customer/bookings.php',       'icon' => 'fa-calendar-check','label' => 'My Bookings'],
        ['href' => APP_URL . '/customer/payment_history.php','icon' => 'fa-receipt',     'label' => 'Payment History'],
        ['href' => APP_URL . '/customer/profile.php',        'icon' => 'fa-user',        'label' => 'Profile'],
    ];
}

$roleColors = ['admin' => '#e94560', 'cashier' => '#f5a623', 'customer' => '#4ecdc4'];
$roleColor  = $roleColors[$_SESSION['user_role']] ?? '#e94560';
$roleLabel  = ucfirst($_SESSION['user_role']);
?>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fa-solid fa-palette"></i>
            <span><?= APP_NAME ?></span>
        </div>
        <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-avatar" style="background: <?= $roleColor ?>20; border: 2px solid <?= $roleColor ?>;">
            <i class="fa-solid fa-user" style="color: <?= $roleColor ?>;"></i>
        </div>
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
            <span class="sidebar-user-role" style="color: <?= $roleColor ?>;"><?= $roleLabel ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?php foreach ($navLinks as $link):
                $isActive = (basename($link['href']) === $currentFile) ? 'active' : '';
            ?>
            <li>
                <a href="<?= $link['href'] ?>" class="nav-link <?= $isActive ?>">
                    <i class="fa-solid <?= $link['icon'] ?>"></i>
                    <span><?= $link['label'] ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= APP_URL ?>/logout.php" class="nav-link logout-link"
           onclick="return confirm('Are you sure you want to log out?')">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- ===== TOP TOPBAR ===== -->
<div class="main-wrapper">
    <header class="topbar">
        <button class="topbar-toggle" id="topbarToggle" aria-label="Toggle menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="topbar-title">
            <?= isset($pageTitle) ? explode(' | ', $pageTitle)[0] : APP_NAME ?>
        </div>
        <div class="topbar-right">
            <span class="topbar-role-badge" style="background: <?= $roleColor ?>20; color: <?= $roleColor ?>; border: 1px solid <?= $roleColor ?>40;">
                <?= $roleLabel ?>
            </span>
            <span class="topbar-user"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
        </div>
    </header>

    <main class="main-content">
        <!-- Flash Messages -->
        <?php displayFlash(); ?>
