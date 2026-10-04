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
        ['href' => APP_URL . '/admin/calendar.php',    'icon' => 'fa-calendar-days',       'label' => 'Event Calendar'],
        ['href' => APP_URL . '/admin/packages.php',    'icon' => 'fa-box-open',            'label' => 'Packages'],
        ['href' => APP_URL . '/admin/components.php',  'icon' => 'fa-puzzle-piece',        'label' => 'Components'],
        ['href' => APP_URL . '/admin/venues.php',      'icon' => 'fa-location-dot',        'label' => 'Venues'],
        ['href' => APP_URL . '/admin/bookings.php',    'icon' => 'fa-calendar-check',      'label' => 'Bookings'],
        ['href' => APP_URL . '/admin/bills.php',       'icon' => 'fa-file-invoice-dollar', 'label' => 'Bills & Payments'],
        ['href' => APP_URL . '/admin/users.php',       'icon' => 'fa-users',               'label' => 'Users'],
        ['href' => APP_URL . '/admin/reviews.php',     'icon' => 'fa-star',               'label' => 'Reviews'],
        ['href' => APP_URL . '/admin/reports.php',     'icon' => 'fa-chart-bar',           'label' => 'Reports'],
        ['href' => APP_URL . '/admin/audit.php',       'icon' => 'fa-shield-halved',       'label' => 'Audit Log'],
    ];

} elseif ($_SESSION['user_role'] === 'staff' || $_SESSION['user_role'] === 'cashier') {
    try {
        $_pendingBookingCount = (int)getDB()->query("SELECT COUNT(*) FROM bookings WHERE status = 'Pending'")->fetchColumn();
    } catch (Exception $e) {
        $_pendingBookingCount = 0;
    }
    try {
        $_pendingSubmissionsCount = (int)getDB()->query("SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {
        $_pendingSubmissionsCount = 0;
    }
    $navLinks = [
        ['href' => APP_URL . '/staff/dashboard.php',           'icon' => 'fa-gauge',              'label' => 'Dashboard'],
        ['href' => APP_URL . '/staff/calendar.php',            'icon' => 'fa-calendar-days',       'label' => 'Event Calendar'],
        ['href' => APP_URL . '/staff/bookings.php',            'icon' => 'fa-calendar-check',      'label' => 'Bookings', 'badge' => $_pendingBookingCount],
        ['href' => APP_URL . '/staff/customers.php',           'icon' => 'fa-user-plus',          'label' => 'Register Customer'],
        ['href' => APP_URL . '/staff/bills.php',               'icon' => 'fa-file-invoice-dollar', 'label' => 'Bills & Balances'],
        ['href' => APP_URL . '/staff/payment_submissions.php', 'icon' => 'fa-money-bill-transfer', 'label' => 'Online Payments', 'badge' => $_pendingSubmissionsCount],
        ['href' => APP_URL . '/staff/collection.php',          'icon' => 'fa-money-bill-wave',     'label' => 'Payments Log'],
        ['href' => APP_URL . '/staff/profile.php',             'icon' => 'fa-user',               'label' => 'Profile'],
    ];
} elseif ($_SESSION['user_role'] === 'customer') {
    $custDueAlerts = function_exists('getCustomerPaymentDueAlerts') ? getCustomerPaymentDueAlerts($_SESSION['user_id'] ?? 0) : [];
    $dueAlertCount = count($custDueAlerts);
    $navLinks = [
        ['href' => APP_URL . '/customer/dashboard.php',       'icon' => 'fa-gauge',              'label' => 'Dashboard'],
        ['href' => APP_URL . '/customer/packages.php',        'icon' => 'fa-box-open',           'label' => 'Browse Packages'],
        ['href' => APP_URL . '/customer/bookings.php',        'icon' => 'fa-calendar-check',     'label' => 'My Bookings', 'badge' => $dueAlertCount],
        ['href' => APP_URL . '/customer/payment_history.php', 'icon' => 'fa-receipt',            'label' => 'Payment History'],
        ['href' => APP_URL . '/customer/profile.php',         'icon' => 'fa-user',               'label' => 'Profile'],
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
<?php
// Notification count for current user
$_notifCount = 0;
$_notifications = [];
try {
    $__uid = (int)($_SESSION['user_id'] ?? 0);
    if ($__uid > 0) {
        $_notifCount    = getUserUnreadNotificationsCount($__uid);
        $_notifications = getUserNotifications($__uid, 10);
    }
} catch (Exception $e) { }
?>
        <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">

            <!-- Notification Bell -->
            <div class="notif-wrapper" style="position:relative;" id="notifWrapper">
                <button id="notifBell" class="notif-bell-btn"
                        style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:7px 11px;cursor:pointer;color:#ddd;font-size:1rem;position:relative;transition:background 0.2s;"
                        title="Notifications" aria-label="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($_notifCount > 0): ?>
                    <span id="notifBadge" style="position:absolute;top:-4px;right:-4px;background:#e94560;color:#fff;border-radius:50%;min-width:17px;height:17px;font-size:0.68rem;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1;">
                        <?= $_notifCount > 9 ? '9+' : $_notifCount ?>
                    </span>
                    <?php else: ?>
                    <span id="notifBadge" style="display:none;position:absolute;top:-4px;right:-4px;background:#e94560;color:#fff;border-radius:50%;min-width:17px;height:17px;font-size:0.68rem;font-weight:800;align-items:center;justify-content:center;padding:0 3px;line-height:1;"></span>
                    <?php endif; ?>
                </button>

                <!-- Notification Dropdown -->
                <div id="notifDropdown" style="display:none;position:absolute;right:0;top:calc(100% + 10px);width:360px;max-height:420px;background:#161626;border:1px solid rgba(255,255,255,0.1);border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,0.7);z-index:9999;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.08);">
                        <div style="font-weight:700;font-size:0.95rem;color:#fff;">
                            <i class="fa-solid fa-bell" style="color:var(--accent-gold);"></i> Notifications
                        </div>
                        <button onclick="markAllRead()" style="background:none;border:none;color:var(--accent-teal);font-size:0.78rem;cursor:pointer;font-weight:600;">
                            ✓ Mark all read
                        </button>
                    </div>
                    <div id="notifList" style="overflow-y:auto;max-height:340px;">
                        <?php if (empty($_notifications)): ?>
                        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:0.88rem;">
                            <i class="fa-solid fa-bell-slash" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                            No notifications yet
                        </div>
                        <?php else: ?>
                        <?php foreach ($_notifications as $notif):
                            $nColors = ['success'=>'#27ae60','error'=>'#e94560','warning'=>'#f5a623','payment'=>'#4ecdc4','info'=>'#4ecdc4'];
                            $nIcons  = ['success'=>'fa-check-circle','error'=>'fa-times-circle','warning'=>'fa-triangle-exclamation','payment'=>'fa-money-bill-transfer','info'=>'fa-circle-info'];
                            $nColor  = $nColors[$notif['type']] ?? '#4ecdc4';
                            $nIcon   = $nIcons[$notif['type']] ?? 'fa-circle-info';
                            $isUnread = !(bool)$notif['is_read'];
                        ?>
                        <div class="notif-item" style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.05);<?= $isUnread ? 'background:rgba(78,205,196,0.04);' : '' ?>display:flex;gap:10px;align-items:flex-start;cursor:pointer;"
                             onclick="<?= !empty($notif['link']) ? 'window.location.href=\''.addslashes($notif['link']).'\'' : '' ?>">
                            <div style="width:32px;height:32px;border-radius:50%;background:<?= $nColor ?>22;border:1px solid <?= $nColor ?>44;display:flex;align-items:center;justify-content:center;color:<?= $nColor ?>;font-size:0.82rem;flex-shrink:0;margin-top:2px;">
                                <i class="fa-solid <?= $nIcon ?>"></i>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:<?= $isUnread ? '700' : '600' ?>;font-size:0.85rem;color:#fff;margin-bottom:2px;"><?= htmlspecialchars($notif['title']) ?></div>
                                <div style="font-size:0.78rem;color:var(--text-secondary);line-height:1.4;white-space:normal;"><?= htmlspecialchars(mb_strimwidth($notif['message'], 0, 100, '...')) ?></div>
                                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;"><?= date('M j, g:i A', strtotime($notif['created_at'])) ?></div>
                            </div>
                            <?php if ($isUnread): ?>
                            <div style="width:8px;height:8px;border-radius:50%;background:var(--accent-teal);flex-shrink:0;margin-top:6px;"></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <span class="topbar-role-badge" style="background: <?= $roleColor ?>20; color: <?= $roleColor ?>; border: 1px solid <?= $roleColor ?>40;">
                <?= $roleLabel ?>
            </span>
            <span class="topbar-user"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
        </div>
    </header>

    <main class="main-content">
        <!-- Flash Messages -->
        <?php displayFlash(); ?>

        <!-- Customer Payment Due Date Notifications -->
        <?php if (($_SESSION['user_role'] ?? '') === 'customer' && !empty($custDueAlerts)): ?>
            <div class="customer-due-notifications" style="margin-bottom:20px;display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($custDueAlerts as $alert):
                    $isOverdue   = $alert['urgency'] === 'overdue';
                    $isDueToday  = $alert['urgency'] === 'due_today';
                    $bannerBg    = $isOverdue ? 'rgba(233,69,96,0.12)' : ($isDueToday ? 'rgba(245,166,35,0.18)' : 'rgba(245,166,35,0.1)');
                    $bannerBorder= $isOverdue ? '#e94560' : '#f5a623';
                    $bannerIcon  = $isOverdue ? 'fa-triangle-exclamation' : ($isDueToday ? 'fa-bell' : 'fa-clock');
                    $iconColor   = $isOverdue ? '#e94560' : '#f5a623';
                    $refText     = htmlspecialchars($alert['booking_reference'] ?? ('BK-' . $alert['id']));
                    $pkgText     = htmlspecialchars($alert['package_name'] ?? 'Package');
                    $balText     = formatCurrency($alert['calculated_balance']);
                    $dateText    = date('F j, Y', strtotime($alert['effective_due_date']));
                ?>
                <div style="background:<?= $bannerBg ?>;border:1px solid <?= $bannerBorder ?>;border-left:5px solid <?= $bannerBorder ?>;border-radius:12px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:36px;height:36px;border-radius:50%;background:<?= $iconColor ?>22;border:1px solid <?= $iconColor ?>55;display:flex;align-items:center;justify-content:center;color:<?= $iconColor ?>;font-size:1rem;flex-shrink:0;">
                            <i class="fa-solid <?= $bannerIcon ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:0.92rem;color:#fff;">
                                <?php if ($isOverdue): ?>
                                    <span style="color:#e94560;text-transform:uppercase;letter-spacing:0.04em;margin-right:6px;">[Overdue Notice]</span>
                                <?php elseif ($isDueToday): ?>
                                    <span style="color:#f5a623;text-transform:uppercase;letter-spacing:0.04em;margin-right:6px;">[Due Today]</span>
                                <?php else: ?>
                                    <span style="color:#f5a623;text-transform:uppercase;letter-spacing:0.04em;margin-right:6px;">[Payment Reminder]</span>
                                <?php endif; ?>
                                Payment of <strong style="color:#27ae60;"><?= $balText ?></strong> for booking <strong style="color:var(--accent-teal);"><?= $refText ?></strong> (<?= $pkgText ?>) is <?= strtolower($alert['urgency_label']) ?> (Due: <?= $dateText ?>).
                            </div>
                            <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:2px;">
                                Please settle your balance with our cashier. All existing bookings must be fully paid before you can purchase another package.
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;flex-shrink:0;">
                        <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-sm" style="background:<?= $bannerBorder ?>;color:#0f0f1a;font-weight:700;">
                            <i class="fa-solid fa-receipt"></i> View Booking
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
