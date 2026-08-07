<?php
// ============================================================
//  Role-Based Navbar - CraftHub Organizer
// ============================================================

$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

$navLinks = [];

if ($_SESSION['user_role'] === 'admin') {
    // Count pending approvals for the badge
    try {
        $_pendingApprovalCount = (int)getDB()->query("SELECT COUNT(*) FROM users WHERE status IN ('pending_approval','inactive')")->fetchColumn();
    } catch (Exception $e) {
        $_pendingApprovalCount = 0;
    }
    $navLinks = [
        ['href' => APP_URL . '/admin/dashboard.php',   'icon' => 'fa-gauge',              'label' => 'Dashboard'],
        ['href' => APP_URL . '/admin/approvals.php',   'icon' => 'fa-user-clock',         'label' => 'Pending Approvals', 'badge' => $_pendingApprovalCount],
        ['href' => APP_URL . '/admin/packages.php',    'icon' => 'fa-box-open',            'label' => 'Packages'],
        ['href' => APP_URL . '/admin/components.php',  'icon' => 'fa-puzzle-piece',        'label' => 'Components'],
        ['href' => APP_URL . '/admin/venues.php',      'icon' => 'fa-location-dot',        'label' => 'Venues'],
        ['href' => APP_URL . '/admin/bookings.php',    'icon' => 'fa-calendar-check',      'label' => 'Bookings'],
        ['href' => APP_URL . '/admin/bills.php',       'icon' => 'fa-file-invoice-dollar', 'label' => 'Bills & Payments'],
        ['href' => APP_URL . '/admin/users.php',       'icon' => 'fa-users',               'label' => 'Users'],
        ['href' => APP_URL . '/admin/reports.php',     'icon' => 'fa-chart-bar',           'label' => 'Reports'],
        ['href' => APP_URL . '/admin/audit.php',       'icon' => 'fa-shield-halved',       'label' => 'Audit Log'],
    ];

} elseif ($_SESSION['user_role'] === 'staff' || $_SESSION['user_role'] === 'cashier') {
    $navLinks = [
        ['href' => APP_URL . '/staff/dashboard.php',       'icon' => 'fa-gauge',              'label' => 'Dashboard'],
        ['href' => APP_URL . '/staff/customers.php',       'icon' => 'fa-user-plus',          'label' => 'Register Customer'],
        ['href' => APP_URL . '/staff/bills.php',           'icon' => 'fa-file-invoice-dollar', 'label' => 'Bills & Balances'],
        ['href' => APP_URL . '/staff/collection.php',      'icon' => 'fa-money-bill-wave',     'label' => 'Payments Log'],
        ['href' => APP_URL . '/staff/reports.php',         'icon' => 'fa-chart-line',          'label' => 'Analytics & Reports'],
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

$roleColors = ['admin' => '#e94560', 'staff' => '#f5a623', 'cashier' => '#f5a623', 'customer' => '#4ecdc4'];
$roleColor  = $roleColors[$_SESSION['user_role']] ?? '#e94560';
$roleLabel  = ucfirst($_SESSION['user_role']);
?>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar" id="sidebar">

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
            $isActive  = (basename($link['href']) === $currentFile) ? 'active' : '';
            $badgeVal  = (int)($link['badge'] ?? 0);
            $isPending = basename($link['href']) === 'approvals.php';
        ?>
        <li>
            <a href="<?= $link['href'] ?>" class="nav-link <?= $isActive ?>"
               style="<?= ($isPending && $badgeVal > 0) ? 'position:relative;' : '' ?>">
                <i class="fa-solid <?= $link['icon'] ?>"
                   style="<?= ($isPending && $badgeVal > 0) ? 'color:#f5a623;' : '' ?>"></i>
                <span><?= $link['label'] ?></span>
                <?php if ($badgeVal > 0): ?>
                <span style="margin-left:auto;min-width:20px;height:20px;background:#f5a623;color:#0f0f1a;border-radius:10px;font-size:0.72rem;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 5px;">
                    <?= $badgeVal ?>
                </span>
                <?php endif; ?>
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
        <div class="topbar-logo">
            <i class="fa-solid fa-palette"></i>
            <span><?= APP_NAME ?></span>
        </div>
        <div class="topbar-divider"></div>
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
